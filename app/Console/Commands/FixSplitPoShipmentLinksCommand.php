<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Logistics\Actions\RecalculatePaymentScheduleForShipmentAction;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\PurchaseOrders\Models\PurchaseOrderItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Redistribui itens de embarque quando uma linha da PI foi dividida em duas ou
 * mais POs.
 *
 * Ao criar itens de embarque, o resolvedor de PO é
 * `PurchaseOrderItem::where('proforma_invoice_item_id', ...)->first()`, ou seja,
 * pega sempre a PO mais antiga. Com uma PO por linha isso sempre funcionou; com
 * a linha dividida em duas POs, TODOS os embarques daquela linha ficam grudados
 * na primeira PO. Consequências:
 *
 *   - a primeira PO aparece com o dobro embarcado (link direto duplicado);
 *   - a segunda PO aparece com o mesmo dobro, porque o fallback por item de PI
 *     dos widgets não é filtrado por PO;
 *   - RecalculatePaymentScheduleForShipmentAction agrupa por PO e cria uma
 *     parcela ship-specific fantasma na PO errada.
 *
 * A redistribuição preserva o que já está certo: cada item de embarque fica na
 * PO atual se ela ainda tiver saldo; só os que sobram é que são realocados, em
 * ordem de criação, para a próxima PO com saldo. Item de embarque cuja
 * quantidade não cabe inteira em nenhuma linha de PO é reportado como ambíguo e
 * não é tocado (exige split manual da linha).
 *
 * Dry-run por padrão; passe --apply para gravar.
 */
class FixSplitPoShipmentLinksCommand extends Command
{
    protected $signature = 'shipments:fix-split-po-links
        {--apply : Persist the changes (otherwise dry-run)}
        {--pi= : Restrict to a single proforma invoice (id or reference)}';

    protected $description = 'Redistribui itens de embarque entre as POs quando uma linha da PI foi dividida em várias POs';

    /** @var list<array{item: ShipmentItem, from: ?int, to: int}> */
    private array $relinks = [];

    /** @var list<array{item: ShipmentItem, reason: string}> */
    private array $ambiguous = [];

    public function __construct(
        private readonly RecalculatePaymentScheduleForShipmentAction $recalculate,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $splitPiItemIds = $this->findSplitPiItemIds();

        if ($splitPiItemIds === []) {
            $this->info('Nenhuma linha de PI dividida entre POs. Nada a fazer.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Linhas de PI divididas entre 2+ POs: %d', count($splitPiItemIds)));

        foreach ($splitPiItemIds as $piItemId) {
            $this->planForPiItem($piItemId);
        }

        $this->reportAmbiguous();

        if ($this->relinks === []) {
            $this->info('Todos os itens de embarque já estão na PO certa. Nada a re-vincular.');

            return self::SUCCESS;
        }

        $this->reportRelinks();

        $staleScheduleItems = $this->findStaleScheduleItems();
        $deletable = $staleScheduleItems->filter(fn (PaymentScheduleItem $psi) => $this->isSafeToDelete($psi));
        $blocked = $staleScheduleItems->reject(fn (PaymentScheduleItem $psi) => $this->isSafeToDelete($psi));

        $this->reportScheduleItems($deletable, $blocked);

        if (! $apply) {
            $this->warn('DRY-RUN: nada foi gravado. Rode com --apply para persistir.');

            return self::SUCCESS;
        }

        $shipments = $this->applyChanges($deletable);

        $this->info(sprintf(
            '✓ %d item(ns) de embarque re-vinculado(s), %d parcela(s) fantasma removida(s).',
            count($this->relinks),
            $deletable->count(),
        ));

        foreach ($shipments as $shipment) {
            $this->recalculate->execute($shipment);
            $this->line("  → cronograma recalculado para {$shipment->reference}");
        }

        return self::SUCCESS;
    }

    /**
     * Linhas de PI cobertas por 2+ linhas de PO (POs ativas).
     *
     * @return list<int>
     */
    private function findSplitPiItemIds(): array
    {
        $query = PurchaseOrderItem::query()
            ->whereNotNull('proforma_invoice_item_id')
            ->whereHas('purchaseOrder');

        if ($pi = $this->option('pi')) {
            $query->whereHas(
                'proformaInvoiceItem.proformaInvoice',
                fn ($q) => $q->where('id', $pi)->orWhere('reference', $pi),
            );
        }

        return $query->select('proforma_invoice_item_id')
            ->groupBy('proforma_invoice_item_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('proforma_invoice_item_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Aloca os itens de embarque dessa linha de PI entre as linhas de PO,
     * respeitando a capacidade de cada uma.
     */
    private function planForPiItem(int $piItemId): void
    {
        $poItems = PurchaseOrderItem::query()
            ->where('proforma_invoice_item_id', $piItemId)
            ->whereHas('purchaseOrder')
            ->orderBy('purchase_order_id')
            ->orderBy('id')
            ->get();

        /** @var array<int, int> $capacity */
        $capacity = $poItems->pluck('quantity', 'id')
            ->map(fn ($qty) => (int) $qty)
            ->all();

        $shipmentItems = ShipmentItem::query()
            ->where('proforma_invoice_item_id', $piItemId)
            ->whereHas('shipment')
            ->with(['shipment:id,reference,etd', 'proformaInvoiceItem:id,description'])
            ->orderBy('id')
            ->get();

        // Passo 1: quem já está numa PO válida com saldo, fica onde está.
        $unplaced = [];

        foreach ($shipmentItems as $item) {
            $current = $item->purchase_order_item_id;
            $qty = (int) $item->quantity;

            if ($current !== null && isset($capacity[$current]) && $capacity[$current] >= $qty) {
                $capacity[$current] -= $qty;

                continue;
            }

            $unplaced[] = $item;
        }

        // Passo 2: os que sobraram vão para a próxima linha de PO com saldo.
        foreach ($unplaced as $item) {
            $qty = (int) $item->quantity;
            $target = null;

            foreach ($capacity as $poItemId => $left) {
                if ($left >= $qty) {
                    $target = $poItemId;

                    break;
                }
            }

            if ($target === null) {
                $this->ambiguous[] = [
                    'item' => $item,
                    'reason' => sprintf('quantidade %d não cabe inteira em nenhuma linha de PO com saldo', $qty),
                ];

                continue;
            }

            $capacity[$target] -= $qty;
            $this->relinks[] = [
                'item' => $item,
                'from' => $item->purchase_order_item_id,
                'to' => $target,
            ];
        }
    }

    /**
     * Parcelas ship-specific presas a uma PO que, depois da redistribuição, não
     * tem mais nenhum item naquele embarque.
     */
    private function findStaleScheduleItems(): \Illuminate\Support\Collection
    {
        // PO ids que cada embarque afetado terá DEPOIS da mudança.
        $poIdsAfterByShipment = [];
        $shipmentIds = [];

        foreach ($this->relinks as $relink) {
            $shipmentIds[] = (int) $relink['item']->shipment_id;
        }

        $shipmentIds = array_values(array_unique($shipmentIds));

        $newPoItemByShipmentItem = [];
        foreach ($this->relinks as $relink) {
            $newPoItemByShipmentItem[$relink['item']->id] = $relink['to'];
        }

        $allItems = ShipmentItem::query()
            ->whereIn('shipment_id', $shipmentIds)
            ->whereNotNull('purchase_order_item_id')
            ->get(['id', 'shipment_id', 'purchase_order_item_id']);

        $poByItem = PurchaseOrderItem::query()
            ->whereIn('id', $allItems->pluck('purchase_order_item_id')->merge(array_values($newPoItemByShipmentItem))->unique())
            ->pluck('purchase_order_id', 'id');

        foreach ($allItems as $item) {
            $poItemId = $newPoItemByShipmentItem[$item->id] ?? $item->purchase_order_item_id;
            $poId = $poByItem[$poItemId] ?? null;

            if ($poId !== null) {
                $poIdsAfterByShipment[$item->shipment_id][] = (int) $poId;
            }
        }

        $stale = collect();

        foreach ($shipmentIds as $shipmentId) {
            $keep = array_values(array_unique($poIdsAfterByShipment[$shipmentId] ?? []));

            $query = PaymentScheduleItem::query()
                ->where('payable_type', PurchaseOrder::class)
                ->where('shipment_id', $shipmentId);

            if ($keep !== []) {
                $query->whereNotIn('payable_id', $keep);
            }

            $stale = $stale->merge($query->get());
        }

        return $stale;
    }

    /** @return list<int> */
    private function touchedPoItemIds(): array
    {
        $ids = [];

        foreach ($this->relinks as $relink) {
            $ids[] = $relink['to'];

            if ($relink['from'] !== null) {
                $ids[] = $relink['from'];
            }
        }

        return array_values(array_unique($ids));
    }

    private function isSafeToDelete(PaymentScheduleItem $psi): bool
    {
        if (in_array($psi->status, [PaymentScheduleStatus::PAID, PaymentScheduleStatus::WAIVED], true)) {
            return false;
        }

        return ! $psi->allocations()->exists();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PaymentScheduleItem>  $deletable
     * @return list<Shipment>
     */
    private function applyChanges(\Illuminate\Support\Collection $deletable): array
    {
        $shipmentIds = [];

        DB::transaction(function () use ($deletable, &$shipmentIds) {
            foreach ($this->relinks as $relink) {
                $item = $relink['item'];
                $item->purchase_order_item_id = $relink['to'];
                $item->save();
                $shipmentIds[] = (int) $item->shipment_id;
            }

            foreach ($deletable as $psi) {
                $psi->delete();
            }
        });

        return Shipment::whereIn('id', array_values(array_unique($shipmentIds)))->get()->all();
    }

    private function reportRelinks(): void
    {
        $poItems = PurchaseOrderItem::query()
            ->whereIn('id', $this->touchedPoItemIds())
            ->with('purchaseOrder:id,reference')
            ->get()
            ->keyBy('id');

        $this->newLine();
        $this->line('<comment>Re-vínculos planejados:</comment>');
        $this->table(
            ['Embarque', 'Ship item', 'Produto', 'Qtd', 'De (PO)', 'Para (PO)'],
            collect($this->relinks)->map(function (array $relink) use ($poItems) {
                $item = $relink['item'];
                $from = $relink['from'] !== null ? $poItems->get($relink['from']) : null;
                $to = $poItems->get($relink['to']);

                return [
                    $item->shipment?->reference ?? '—',
                    $item->id,
                    mb_strimwidth($item->proformaInvoiceItem?->description ?? '—', 0, 32, '…'),
                    $item->quantity,
                    $from?->purchaseOrder?->reference ?? '— (sem link)',
                    $to?->purchaseOrder?->reference ?? '?',
                ];
            })->all(),
        );
    }

    private function reportAmbiguous(): void
    {
        if ($this->ambiguous === []) {
            return;
        }

        $this->newLine();
        $this->warn(sprintf('%d item(ns) ambíguo(s) — não tocados, exigem split manual da linha:', count($this->ambiguous)));
        $this->table(
            ['Embarque', 'Ship item', 'Produto', 'Motivo'],
            collect($this->ambiguous)->map(fn (array $row) => [
                $row['item']->shipment?->reference ?? '—',
                $row['item']->id,
                mb_strimwidth($row['item']->proformaInvoiceItem?->description ?? '—', 0, 32, '…'),
                $row['reason'],
            ])->all(),
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PaymentScheduleItem>  $deletable
     * @param  \Illuminate\Support\Collection<int, PaymentScheduleItem>  $blocked
     */
    private function reportScheduleItems(\Illuminate\Support\Collection $deletable, \Illuminate\Support\Collection $blocked): void
    {
        if ($deletable->isNotEmpty()) {
            $this->newLine();
            $this->line('<comment>Parcelas fantasma a remover (PO perde o embarque):</comment>');
            $this->table(
                ['PSI', 'PO', 'Rótulo', 'Valor', 'Status'],
                $deletable->map(fn (PaymentScheduleItem $psi) => [
                    $psi->id,
                    $psi->payable?->reference ?? $psi->payable_id,
                    mb_strimwidth((string) $psi->label, 0, 50, '…'),
                    number_format($psi->amount / 100, 2),
                    $psi->status instanceof PaymentScheduleStatus ? $psi->status->value : (string) $psi->status,
                ])->all(),
            );
        }

        if ($blocked->isNotEmpty()) {
            $this->newLine();
            $this->warn($blocked->count().' parcela(s) na PO errada com pagamento/baixa — exigem revisão manual, NÃO serão removidas:');
            $this->table(
                ['PSI', 'PO', 'Rótulo', 'Valor', 'Status', 'Alocado'],
                $blocked->map(fn (PaymentScheduleItem $psi) => [
                    $psi->id,
                    $psi->payable?->reference ?? $psi->payable_id,
                    mb_strimwidth((string) $psi->label, 0, 50, '…'),
                    number_format($psi->amount / 100, 2),
                    $psi->status instanceof PaymentScheduleStatus ? $psi->status->value : (string) $psi->status,
                    number_format((int) $psi->allocations()->sum('allocated_amount') / 100, 2),
                ])->all(),
            );
        }
    }
}
