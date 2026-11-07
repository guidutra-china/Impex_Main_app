<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Põe a alocação de uma etapa dependente de embarque na linha certa.
 *
 * Quando o pagamento entra antes do embarque existir, ele fica no `[remaining]`
 * do documento; e quando um embarque é criado errado e depois retirado, a
 * parcela dele continua segurando o dinheiro. Nos dois casos o embarque real
 * aparece em aberto mesmo com o cliente/fornecedor já tendo pago — foi o que
 * aconteceu na PI-2026-00049, com US$ 1.702.120 pendurados no `[remaining]` e
 * no SH-2026-00033 enquanto SH-42 e SH-47 constavam pendentes pelo mesmo total.
 *
 * A redistribuição enche as parcelas dos embarques reais em ordem de data de
 * pagamento, partindo uma alocação quando ela cruza a fronteira entre dois
 * embarques. Não cria nem destrói dinheiro: o total alocado antes e depois é o
 * mesmo, e o que sobra volta para o saldo não embarcado.
 *
 * Dry-run por padrão; passe --apply para gravar.
 */
class RebalanceStageAllocationsCommand extends Command
{
    protected $signature = 'financial:rebalance-stage-allocations
        {document : Referência da PI ou PO (ex.: PI-2026-00049)}
        {--apply : Persist the changes (otherwise dry-run)}';

    protected $description = 'Redistribui alocações de etapas dependentes de embarque para as parcelas dos embarques reais';

    /** @var list<array{allocation: PaymentAllocation, from: PaymentScheduleItem, to: PaymentScheduleItem, amount: int, split: bool}> */
    private array $moves = [];

    /** @var list<PaymentScheduleItem> */
    private array $detach = [];

    /** @var list<PaymentScheduleItem> */
    private array $drop = [];

    public function handle(): int
    {
        $document = $this->resolveDocument((string) $this->argument('document'));

        if (! $document) {
            $this->error('Documento não encontrado: '.$this->argument('document'));

            return self::FAILURE;
        }

        $this->info(sprintf('Documento: %s (%s)', $document->reference, class_basename($document)));

        foreach ($this->stageGroups($document) as $items) {
            $this->planForStage($items);
        }

        if ($this->moves === [] && $this->detach === [] && $this->drop === []) {
            $this->info('Nada a redistribuir — as parcelas já estão nos embarques certos.');

            return self::SUCCESS;
        }

        $this->report();

        if (! $this->option('apply')) {
            $this->warn('DRY-RUN: nada foi gravado. Rode com --apply para persistir.');

            return self::SUCCESS;
        }

        $this->apply();

        $this->info(sprintf(
            '✓ %d alocação(ões) movida(s), %d parcela(s) destacada(s), %d removida(s).',
            count($this->moves),
            count($this->detach),
            count($this->drop),
        ));

        return self::SUCCESS;
    }

    private function resolveDocument(string $reference): ?Model
    {
        return ProformaInvoice::where('reference', $reference)->first()
            ?? PurchaseOrder::where('reference', $reference)->first();
    }

    /**
     * Parcelas dependentes de embarque do documento, agrupadas por etapa.
     *
     * Sem `payment_term_stage_id` (parcelas antigas) o agrupamento cai no
     * `due_condition`, que é o que de fato define a etapa.
     *
     * @return Collection<string, Collection<int, PaymentScheduleItem>>
     */
    private function stageGroups(Model $document): Collection
    {
        return PaymentScheduleItem::query()
            ->where('payable_type', $document::class)
            ->where('payable_id', $document->id)
            ->whereNull('source_type')
            ->with(['allocations.payment', 'shipment'])
            ->get()
            ->filter(fn (PaymentScheduleItem $item) => $this->isShipmentDependent($item))
            ->groupBy(fn (PaymentScheduleItem $item) => $item->payment_term_stage_id !== null
                ? 'stage:'.$item->payment_term_stage_id
                : 'cond:'.$this->dueCondition($item));
    }

    private function isShipmentDependent(PaymentScheduleItem $item): bool
    {
        return in_array($this->dueCondition($item), ['before_shipment', 'after_shipment', 'delivery_date', 'bl_date'], true);
    }

    private function dueCondition(PaymentScheduleItem $item): string
    {
        $condition = $item->due_condition;

        return $condition instanceof \BackedEnum ? (string) $condition->value : (string) $condition;
    }

    /**
     * @param  Collection<int, PaymentScheduleItem>  $items
     */
    private function planForStage(Collection $items): void
    {
        [$targets, $sources] = $items->partition(fn (PaymentScheduleItem $item) => $this->isRealShipmentLine($item));

        $sources = $sources->filter(fn (PaymentScheduleItem $item) => $item->allocations->isNotEmpty());

        // Chave composta de propósito: sortBy() com ARRAY de closures trata
        // cada closure como comparador ($a, $b), não como extrator de chave,
        // e a ordenação sai embaralhada sem erro nenhum.
        $byPaymentDate = fn (array $row) => sprintf(
            '%s|%012d',
            $row['allocation']->payment?->payment_date?->toDateString() ?? '9999-12-31',
            $row['allocation']->id,
        );

        // Alocações das fontes em ordem de pagamento — é a ordem em que o
        // dinheiro entrou, e portanto a ordem em que cobre os embarques.
        $pool = $sources
            ->flatMap(fn (PaymentScheduleItem $source) => $source->allocations->map(fn (PaymentAllocation $a) => [
                'allocation' => $a,
                'source' => $source,
                'amount' => (int) $a->allocated_amount_in_document_currency,
            ]))
            // O que foi pago A MAIS numa parcela de embarque real também é
            // fonte (PO-2026-00071: o 70% inteiro entrou no SH-57 e a parcela
            // do SH-68 ficou em aberto).
            ->concat($targets->flatMap(fn (PaymentScheduleItem $target) => $this->excessRows($target)))
            ->sortBy($byPaymentDate)
            ->values();

        if ($pool->isEmpty()) {
            return;
        }

        $targets = $targets
            ->sortBy(fn (PaymentScheduleItem $item) => sprintf(
                '%s|%012d',
                $item->shipment?->etd?->toDateString() ?? '9999-12-31',
                $item->id,
            ))
            ->values();

        // Saldo ainda não movido de cada alocação: decide se a fatia parte a
        // alocação ou só troca o dono dela.
        $unmoved = $pool->mapWithKeys(fn (array $row) => [
            $row['allocation']->id => (int) $row['allocation']->allocated_amount_in_document_currency,
        ])->all();

        $cursor = 0;
        $available = (int) $pool[0]['amount'];

        foreach ($targets as $target) {
            $need = (int) $target->amount - (int) $target->allocations->sum('allocated_amount_in_document_currency');

            while ($need > 0 && $cursor < $pool->count()) {
                $row = $pool[$cursor];
                $take = min($need, $available);

                if ($take <= 0 || $row['source']->is($target)) {
                    $cursor++;
                    $available = $cursor < $pool->count() ? (int) $pool[$cursor]['amount'] : 0;

                    continue;
                }

                $allocationId = $row['allocation']->id;

                $this->moves[] = [
                    'allocation' => $row['allocation'],
                    'from' => $row['source'],
                    'to' => $target,
                    'amount' => $take,
                    // Relativo ao saldo AINDA não movido desta alocação: a
                    // última fatia não parte nada, só muda de dono.
                    'split' => $take < $unmoved[$allocationId],
                ];

                $unmoved[$allocationId] -= $take;
                $need -= $take;
                $available -= $take;

                if ($available === 0) {
                    $cursor++;
                    $available = $cursor < $pool->count() ? (int) $pool[$cursor]['amount'] : 0;
                }
            }
        }

        $movedBySource = [];

        foreach ($this->moves as $move) {
            $movedBySource[$move['from']->id] = ($movedBySource[$move['from']->id] ?? 0) + $move['amount'];
        }

        foreach ($sources as $source) {
            $total = (int) $source->allocations->sum('allocated_amount_in_document_currency');

            if (($movedBySource[$source->id] ?? 0) === $total) {
                $this->drop[] = $source;

                continue;
            }

            // Sobrou dinheiro nesta fonte. Se ela está presa a um embarque que
            // não carrega nada, volta a ser parcela do saldo não embarcado.
            if ($source->shipment_id !== null) {
                $this->detach[] = $source;
            }
        }
    }

    /**
     * Fatias do que foi pago além do valor de uma parcela de embarque real,
     * tiradas do dinheiro que entrou por último. Só alocação em dinheiro:
     * crédito aplicado (desconto, CN) fica onde está.
     *
     * @return list<array{allocation: PaymentAllocation, source: PaymentScheduleItem, amount: int}>
     */
    private function excessRows(PaymentScheduleItem $target): array
    {
        $excess = (int) $target->allocations->sum('allocated_amount_in_document_currency') - (int) $target->amount;

        // Mesma tolerância de arredondamento do PaymentScheduleItem (0,01).
        if ($excess <= 100) {
            return [];
        }

        $rows = [];
        $cash = $target->allocations
            ->whereNull('credit_schedule_item_id')
            ->sortByDesc(fn (PaymentAllocation $a) => sprintf(
                '%s|%012d',
                $a->payment?->payment_date?->toDateString() ?? '0000-00-00',
                $a->id,
            ));

        foreach ($cash as $allocation) {
            if ($excess <= 0) {
                break;
            }

            $slice = min($excess, (int) $allocation->allocated_amount_in_document_currency);
            $rows[] = ['allocation' => $allocation, 'source' => $target, 'amount' => $slice];
            $excess -= $slice;
        }

        return $rows;
    }

    /**
     * Uma parcela é de embarque real quando o embarque existe e carrega itens.
     * A do SH-2026-00033 (retirado, sem itens) não é.
     */
    private function isRealShipmentLine(PaymentScheduleItem $item): bool
    {
        if ($item->shipment_id === null) {
            return false;
        }

        $shipment = $item->shipment;

        if (! $shipment instanceof Shipment) {
            return false;
        }

        return $shipment->items()->exists();
    }

    private function apply(): void
    {
        DB::transaction(function () {
            foreach ($this->moves as $move) {
                $allocation = $move['allocation'];
                $full = (int) $allocation->allocated_amount_in_document_currency;

                if (! $move['split']) {
                    $allocation->update(['payment_schedule_item_id' => $move['to']->id]);

                    continue;
                }

                // Parte a alocação: a fatia vai para o destino e o resto fica
                // onde está para a próxima volta. Os valores na moeda do
                // pagamento acompanham a proporção, preservando a taxa.
                $ratio = $move['amount'] / $full;
                $sliceInPaymentCurrency = (int) round((int) $allocation->allocated_amount * $ratio);

                PaymentAllocation::create([
                    'payment_id' => $allocation->payment_id,
                    'payment_schedule_item_id' => $move['to']->id,
                    'credit_schedule_item_id' => $allocation->credit_schedule_item_id,
                    'allocated_amount' => $sliceInPaymentCurrency,
                    'exchange_rate' => $allocation->exchange_rate,
                    'exchange_rate_captured_at' => $allocation->exchange_rate_captured_at,
                    'allocated_amount_in_document_currency' => $move['amount'],
                    'created_by' => $allocation->created_by,
                    'created_at' => $allocation->created_at,
                ]);

                $allocation->update([
                    'allocated_amount' => (int) $allocation->allocated_amount - $sliceInPaymentCurrency,
                    'allocated_amount_in_document_currency' => $full - $move['amount'],
                ]);
            }

            foreach ($this->detach as $item) {
                $item->update([
                    'shipment_id' => null,
                    'label' => $this->baseLabel($item),
                ]);
            }

            foreach ($this->drop as $item) {
                if ($item->allocations()->exists()) {
                    // Sobrou alocação que o plano não previu — não apaga.
                    continue;
                }

                $item->delete();
            }
        });

        // Fora da transação: recalcula o status de tudo que mudou de mão.
        collect($this->moves)
            ->flatMap(fn (array $move) => [$move['to'], $move['from']])
            ->concat($this->detach)
            ->unique('id')
            ->each(function (PaymentScheduleItem $item) {
                if ($item->exists && PaymentScheduleItem::whereKey($item->id)->exists()) {
                    $item->fresh()?->recalculateStatus();
                }
            });
    }

    /** Tira o "[SH-XXX / " do rótulo, devolvendo a forma sem embarque. */
    private function baseLabel(PaymentScheduleItem $item): string
    {
        $label = (string) $item->label;

        return trim(preg_replace('#\s*—\s*\[[^/\]]+/\s*([^\]]+)\]#', ' — [$1]', $label) ?? $label);
    }

    private function report(): void
    {
        if ($this->moves !== []) {
            $this->newLine();
            $this->line('<comment>Alocações a mover:</comment>');
            $this->table(
                ['Aloc', 'Pagamento', 'Data', 'Valor (doc)', 'De', 'Para', 'Parte?'],
                collect($this->moves)->map(fn (array $move) => [
                    $move['allocation']->id,
                    $move['allocation']->payment_id,
                    $move['allocation']->payment?->payment_date?->format('d/m/Y') ?? '—',
                    Money::format($move['amount']),
                    mb_strimwidth((string) $move['from']->label, 0, 42, '…'),
                    mb_strimwidth((string) $move['to']->label, 0, 42, '…'),
                    $move['split'] ? 'sim' : '',
                ])->all(),
            );
        }

        if ($this->detach !== []) {
            $this->newLine();
            $this->line('<comment>Parcelas a destacar do embarque (mantêm o dinheiro):</comment>');
            $this->table(
                ['PSI', 'Rótulo atual', 'Vira', 'Alocado'],
                collect($this->detach)->map(fn (PaymentScheduleItem $item) => [
                    $item->id,
                    mb_strimwidth((string) $item->label, 0, 42, '…'),
                    mb_strimwidth($this->baseLabel($item), 0, 42, '…'),
                    Money::format((int) $item->allocations->sum('allocated_amount_in_document_currency')),
                ])->all(),
            );
        }

        if ($this->drop !== []) {
            $this->newLine();
            $this->line('<comment>Parcelas a remover (ficam sem alocação nenhuma):</comment>');
            $this->table(
                ['PSI', 'Rótulo', 'Valor da linha'],
                collect($this->drop)->map(fn (PaymentScheduleItem $item) => [
                    $item->id,
                    mb_strimwidth((string) $item->label, 0, 52, '…'),
                    Money::format((int) $item->amount),
                ])->all(),
            );
        }
    }
}
