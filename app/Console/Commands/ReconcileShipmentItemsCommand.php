<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Logistics\Actions\RecalculatePaymentScheduleForShipmentAction;
use App\Domain\Logistics\Actions\ResolvePurchaseOrderItemForShipmentAction;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\PurchaseOrders\Models\PurchaseOrderItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Leva os itens de um embarque ao estado declarado num JSON versionado.
 *
 * Existe para o embarque consolidado SH-2026-00020 (BL SHAE26090561): os itens
 * foram colados mais de uma vez na tela, ficaram linhas repetidas, quantidades
 * com o total da PI em embarque parcial e linhas faltando. O arquivo é a lista
 * final de itens (PI, item da PI, quantidade); o comando compara com o que o
 * embarque tem e corrige:
 *
 *   - linhas repetidas do mesmo item da PI: mantém a mais antiga e apaga as
 *     demais (pelo model, para os hooks rodarem);
 *   - quantidade diferente da declarada: ajusta;
 *   - item declarado e ausente: cria, escolhendo a linha de PO pelo saldo
 *     (ResolvePurchaseOrderItemForShipmentAction). Se a PI não tem linha de PO
 *     ligada, religa a linha de PO órfã (proforma_invoice_item_id nulo) do
 *     mesmo produto e da mesma quantidade — só quando há exatamente uma;
 *   - item no embarque que o arquivo não declara: NÃO é apagado, só reportado
 *     (use --delete-extras para apagar).
 *
 * Cada item do arquivo é conferido contra o banco (PI e início da descrição)
 * antes de qualquer escrita, para que ids divergentes entre dev e produção
 * abortem em vez de gravar o item errado. No fim recalcula o cronograma do
 * embarque; rode financial:audit-stale-schedules (sem --fix) para conferir.
 *
 * Dry-run por padrão; passe --apply para gravar, tudo numa transação.
 */
class ReconcileShipmentItemsCommand extends Command
{
    protected $signature = 'shipments:reconcile-items
        {file : JSON de itens (nome dentro de database/data/loading-lists ou caminho completo)}
        {--shipment= : Referência do embarque, sobrescrevendo a declarada no arquivo}
        {--delete-extras : Apaga itens do embarque que o arquivo não declara}
        {--apply : Grava as alterações (padrão: dry-run)}';

    protected $description = 'Reconcilia os itens de um embarque com a lista final declarada em JSON (duplicadas, quantidades, faltantes)';

    public function __construct(
        private readonly ResolvePurchaseOrderItemForShipmentAction $resolvePoItem,
        private readonly RecalculatePaymentScheduleForShipmentAction $recalculateSchedule,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $data = $this->readFile((string) $this->argument('file'));
            $shipment = $this->resolveShipment($data);
            $declared = $this->validateDeclared($data['items']);
            $plan = $this->buildPlan($shipment, $declared);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->render($shipment, $plan);

        if (! $plan['changes']) {
            $this->info('Nada a fazer: o embarque já está como o arquivo declara.');

            return self::SUCCESS;
        }

        if (! $this->option('apply')) {
            $this->newLine();
            $this->comment('Dry-run — nada foi gravado. Rode de novo com --apply para aplicar.');

            return self::SUCCESS;
        }

        try {
            DB::transaction(fn () => $this->apply($shipment, $plan));
        } catch (RuntimeException $e) {
            $this->error('Nada foi gravado: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('✓ Itens reconciliados e cronograma do embarque recalculado.');
        $this->line('Confira com: php artisan financial:audit-stale-schedules');

        return self::SUCCESS;
    }

    private function readFile(string $file): array
    {
        $path = str_contains($file, '/') ? $file : database_path('data/loading-lists/'.$file);

        if (! str_ends_with($path, '.json')) {
            $path .= '.json';
        }

        if (! is_file($path)) {
            throw new RuntimeException("Arquivo não encontrado: {$path}");
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data) || ! is_array($data['items'] ?? null) || $data['items'] === []) {
            throw new RuntimeException("JSON inválido ou sem itens: {$path}");
        }

        $this->line("Arquivo: <info>{$path}</info>");

        return $data;
    }

    private function resolveShipment(array $data): Shipment
    {
        $reference = (string) ($this->option('shipment') ?: ($data['shipment'] ?? ''));
        $shipment = $reference === '' ? null : Shipment::query()->where('reference', $reference)->first();

        if (! $shipment) {
            throw new RuntimeException("Embarque não encontrado: {$reference}");
        }

        return $shipment;
    }

    /**
     * Confere cada item do arquivo contra o banco antes de qualquer escrita.
     *
     * @return array<int, array{quantity: int, pi: string, description: string, item: ProformaInvoiceItem}>
     */
    private function validateDeclared(array $items): array
    {
        $byId = [];

        foreach ($items as $row) {
            $id = (int) ($row['pi_item_id'] ?? 0);

            if ($id <= 0 || ($row['quantity'] ?? 0) < 1) {
                throw new RuntimeException('Item do arquivo sem pi_item_id ou com quantidade inválida: '.json_encode($row));
            }

            if (isset($byId[$id])) {
                throw new RuntimeException("Item da PI {$id} aparece duas vezes no arquivo.");
            }

            $byId[$id] = $row;
        }

        $piItems = ProformaInvoiceItem::query()
            ->with('proformaInvoice:id,reference')
            ->whereIn('id', array_keys($byId))
            ->get()
            ->keyBy('id');

        $errors = [];
        $declared = [];

        foreach ($byId as $id => $row) {
            $piItem = $piItems->get($id);
            $expectedDescription = $this->normalize((string) ($row['description'] ?? ''));

            if (! $piItem) {
                $errors[] = "Item da PI {$id} ({$row['pi']}) não existe neste banco.";

                continue;
            }

            if ($piItem->proformaInvoice?->reference !== $row['pi']) {
                $errors[] = "Item da PI {$id} pertence a {$piItem->proformaInvoice?->reference}, o arquivo diz {$row['pi']}.";

                continue;
            }

            if ($expectedDescription !== '' && ! str_starts_with($this->normalize((string) $piItem->description), $expectedDescription)) {
                $errors[] = "Item da PI {$id} ({$row['pi']}): a descrição no banco não começa com \"{$row['description']}\".";

                continue;
            }

            $declared[$id] = [
                'quantity' => (int) $row['quantity'],
                'pi' => $row['pi'],
                'description' => (string) ($row['description'] ?? ''),
                'item' => $piItem,
            ];
        }

        if ($errors !== []) {
            throw new RuntimeException("O arquivo não casa com este banco:\n  - ".implode("\n  - ", array_slice($errors, 0, 25)));
        }

        return $declared;
    }

    private function normalize(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * @param  array<int, array{quantity: int, pi: string, description: string, item: ProformaInvoiceItem}>  $declared
     */
    private function buildPlan(Shipment $shipment, array $declared): array
    {
        $current = ShipmentItem::query()
            ->where('shipment_id', $shipment->id)
            ->orderBy('id')
            ->get()
            ->groupBy('proforma_invoice_item_id');

        $plan = ['duplicates' => [], 'quantities' => [], 'creates' => [], 'relinks' => [], 'extras' => []];

        foreach ($current as $piItemId => $rows) {
            if (! isset($declared[$piItemId])) {
                foreach ($rows as $row) {
                    $plan['extras'][] = $row;
                }

                continue;
            }

            foreach ($rows->slice(1) as $duplicate) {
                $plan['duplicates'][] = $duplicate;
            }

            $keep = $rows->first();

            if ((int) $keep->quantity !== $declared[$piItemId]['quantity']) {
                $plan['quantities'][] = [$keep, $declared[$piItemId]['quantity']];
            }
        }

        foreach ($declared as $piItemId => $row) {
            if ($current->has($piItemId)) {
                continue;
            }

            // Dry-run: o saldo da PO ainda inclui as linhas repetidas e as quantidades
            // erradas que o --apply corrige antes de criar; aqui só se prevê a religação.
            if ($this->resolvePoItem->execute($piItemId, $row['quantity']) === null
                && ($orphan = $this->orphanPoItemFor($row['item'])) !== null) {
                $plan['relinks'][] = [$orphan, $piItemId];
            }

            $plan['creates'][] = [$piItemId, $row['quantity'], $row['pi'], $row['description']];
        }

        $plan['changes'] = $plan['duplicates'] !== [] || $plan['quantities'] !== [] || $plan['creates'] !== []
            || ($this->option('delete-extras') && $plan['extras'] !== []);

        return $plan;
    }

    /**
     * Linha de PO da mesma PI que perdeu a chave (FK set null): mesmo produto e
     * mesma quantidade do item da PI, e só se for a única.
     */
    private function orphanPoItemFor(ProformaInvoiceItem $piItem): ?PurchaseOrderItem
    {
        $candidates = PurchaseOrderItem::query()
            ->whereNull('proforma_invoice_item_id')
            ->where('product_id', $piItem->product_id)
            ->where('quantity', $piItem->quantity)
            ->whereHas('purchaseOrder', fn ($q) => $q->where('proforma_invoice_id', $piItem->proforma_invoice_id))
            ->get();

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    private function render(Shipment $shipment, array $plan): void
    {
        $this->line("Embarque: <info>{$shipment->reference}</info> (#{$shipment->id}, {$shipment->status->value})");
        $this->line(sprintf(
            'Linhas repetidas a apagar: %d | quantidades a ajustar: %d | itens a criar: %d (religando %d linha(s) de PO órfã) | fora do arquivo: %d%s',
            count($plan['duplicates']),
            count($plan['quantities']),
            count($plan['creates']),
            count($plan['relinks']),
            count($plan['extras']),
            $this->option('delete-extras') ? ' (serão apagados)' : ' (mantidos)',
        ));

        if ($plan['quantities'] !== []) {
            $this->table(['Item', 'PI item', 'Atual', 'Declarado'], array_map(
                fn (array $q) => [$q[0]->id, $q[0]->proforma_invoice_item_id, (int) $q[0]->quantity, $q[1]],
                $plan['quantities'],
            ));
        }

        if ($plan['creates'] !== []) {
            $this->table(['PI', 'PI item', 'Qtd', 'Descrição'], array_map(
                fn (array $c) => [$c[2], $c[0], $c[1], mb_strimwidth($c[3], 0, 40, '…')],
                $plan['creates'],
            ));
        }

        if ($plan['relinks'] !== []) {
            $this->table(['Linha de PO órfã', '→ PI item'], array_map(fn (array $r) => [$r[0]->id, $r[1]], $plan['relinks']));
        }

    }

    private function apply(Shipment $shipment, array $plan): void
    {
        foreach ($plan['duplicates'] as $duplicate) {
            $duplicate->delete();
        }

        if ($this->option('delete-extras')) {
            foreach ($plan['extras'] as $extra) {
                $extra->delete();
            }
        }

        foreach ($plan['quantities'] as [$item, $quantity]) {
            $item->quantity = $quantity;
            $item->save();
        }

        $sortOrder = (int) ShipmentItem::query()->where('shipment_id', $shipment->id)->max('sort_order');

        foreach ($plan['creates'] as [$piItemId, $quantity]) {
            $po = $this->resolvePoItem->execute($piItemId, $quantity);

            if ($po === null && ($orphan = $this->orphanPoItemFor(ProformaInvoiceItem::findOrFail($piItemId))) !== null) {
                $orphan->proforma_invoice_item_id = $piItemId;
                $orphan->save();
                $po = $this->resolvePoItem->execute($piItemId, $quantity);
            }

            if ($po === null) {
                throw new RuntimeException("item da PI {$piItemId} continua sem linha de PO.");
            }

            ShipmentItem::create([
                'shipment_id' => $shipment->id,
                'proforma_invoice_item_id' => $piItemId,
                'purchase_order_item_id' => $po->id,
                'quantity' => $quantity,
                'sort_order' => ++$sortOrder,
            ]);
        }

        $this->recalculateSchedule->execute($shipment->refresh());
    }
}
