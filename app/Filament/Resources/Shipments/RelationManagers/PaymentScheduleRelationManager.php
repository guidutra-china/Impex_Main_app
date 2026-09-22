<?php

namespace App\Filament\Resources\Shipments\RelationManagers;

use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Financial\Support\InstallmentStage;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\Settings\Enums\CalculationBase;
use App\Filament\RelationManagers\PaymentScheduleRelationManager as BasePaymentScheduleRelationManager;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PaymentScheduleRelationManager extends BasePaymentScheduleRelationManager
{
    /**
     * Extend the base morphMany('payable') query so the table ALSO surfaces
     * the parcels of the documents this shipment carries:
     *
     * - PurchaseOrder parcels carved out for this shipment (supplier side);
     * - ProformaInvoice parcels carved out for this shipment (client side);
     * - ProformaInvoice document-level parcels (order date, before production,
     *   …) of the PIs in this shipment. Those stages are charged once per PI
     *   and never get a shipment-specific carve-out, so without this branch a
     *   shipment of a "100% in advance" PI shows only its additional costs and
     *   looks like the client owes nothing (SH-2026-00041 / PI-2026-00078).
     *
     * [remaining] rows — a shipment-dependent stage with no shipment link — are
     * the not-yet-shipped balance of the document and stay out, same rule as
     * ShipmentPaymentSummaryService.
     *
     * Using orWhere here preserves the morphMany base constraint
     * (payable_type=Shipment AND payable_id=ship.id) so existing items
     * — freight to forwarder, freight charged to client, etc. — keep showing.
     */
    public function table(Table $table): Table
    {
        $shipment = $this->getOwnerRecord();

        // Estágios de PI que o embarque JÁ mostra pelo espelho (payable = o
        // próprio embarque). Espelho e canônica renderizam idênticos, então
        // listar os dois duplicava a parcela na aba (SH-2026-00056 /
        // PI-2026-00017, e todo embarque com espelho desde ebd3cbfd).
        $mirrored = $this->mirroredPiStages($shipment);

        return parent::table($table)
            // Parcelas iguais (mesmo estágio, lado e moeda) somadas num grupo
            // que abre recolhido: o cabeçalho traz a soma, expandir mostra as
            // invoices. Só no embarque — é ele que carrega várias PIs/POs.
            ->groups([$this->stageGroup()])
            ->defaultGroup('stage')
            ->collapsedGroupsByDefault()
            ->groupingSettingsHidden()
            // Dentro do grupo, a ordem natural das parcelas.
            ->defaultSort('sort_order')
            // reorder(): a relação paymentScheduleItems() já vem com
            // orderBy('sort_order'), que venceria a ordenação do grupo e
            // QUEBRAVA um grupo em vários cabeçalhos (no SH-2026-00056 os
            // estágios do fornecedor saíam intercalados: 30%, 60%, 30%, 60%).
            ->modifyQueryUsing(fn (Builder $query) => $query->reorder()->orWhere(
                fn (Builder $q) => $q
                    ->where('shipment_id', $shipment->id)
                    ->where('payable_type', PurchaseOrder::class),
            )->orWhere(
                // Canônica da PI só quando é a ÚNICA representação da parcela
                // (embarque sem espelho, ex.: SH-2026-00054).
                fn (Builder $q) => $q
                    ->where('shipment_id', $shipment->id)
                    ->where('payable_type', ProformaInvoice::class)
                    ->where(function (Builder $unmirrored) use ($mirrored) {
                        foreach ($mirrored as [$piId, $dueCondition]) {
                            // Escrito sem NOT(...) de propósito: com due_condition
                            // NULL a negação viraria NULL e esconderia a linha.
                            $unmirrored->where(fn (Builder $other) => $other
                                ->where('payable_id', '!=', $piId)
                                ->orWhere('due_condition', '!=', $dueCondition)
                                ->orWhereNull('due_condition'));
                        }
                    }),
            )->orWhere(
                fn (Builder $q) => $q
                    ->where('payable_type', ProformaInvoice::class)
                    ->whereNull('shipment_id')
                    ->whereIn('due_condition', CalculationBase::documentLevelValues())
                    ->whereIn('payable_id', $this->carriedProformaInvoiceIds($shipment)),
            ));
    }

    /** Chave do grupo — regra em {@see InstallmentStage}, compartilhada com o portal do cliente. */
    public static function stageKey(PaymentScheduleItem $record): string
    {
        return InstallmentStage::key($record);
    }

    protected static function stageBucket(PaymentScheduleItem $record): string
    {
        return InstallmentStage::bucket($record);
    }

    /**
     * Ordem dos estágios na vida do pedido — a de declaração do enum não é
     * cronológica (delivery_date vem antes de before_shipment). Condição nova
     * no enum precisa entrar aqui; há teste cobrando.
     *
     * @var list<CalculationBase>
     */
    public const STAGE_CHRONOLOGY = [
        CalculationBase::ORDER_DATE,
        CalculationBase::PO_DATE,
        CalculationBase::INVOICE_DATE,
        CalculationBase::BEFORE_PRODUCTION,
        CalculationBase::AFTER_PRODUCTION,
        CalculationBase::BEFORE_SHIPMENT,
        CalculationBase::SHIPMENT_DATE,
        CalculationBase::BL_DATE,
        CalculationBase::DELIVERY_DATE,
    ];

    protected function stageGroup(): Group
    {
        return Group::make('stage')
            ->label(__('forms.labels.installment'))
            ->collapsible()
            ->titlePrefixedWithLabel(false)
            ->getKeyFromRecordUsing(fn (PaymentScheduleItem $record) => self::stageKey($record))
            ->getTitleFromRecordUsing(fn (PaymentScheduleItem $record) => $this->stageTitle($record))
            ->getDescriptionFromRecordUsing(fn (PaymentScheduleItem $record) => $this->stageDescription($record))
            ->orderQueryUsing(fn (Builder $query, string $direction) => $this->orderByStage($query, $direction))
            ->scopeQueryByKeyUsing(fn (Builder $query, ?string $key) => $this->scopeToStage($query, (string) $key));
    }

    /**
     * O Filament guarda o estado recolhido pelo TÍTULO — por isso ele carrega
     * o lado sempre, e a moeda quando o embarque tem mais de uma.
     */
    protected function stageTitle(PaymentScheduleItem $record): string
    {
        $bucket = self::stageBucket($record);

        $title = match ($bucket) {
            'client', 'supplier' => InstallmentStage::cleanLabel($record),
            'cost_in', 'cost_out' => __('forms.labels.additional_costs'),
            default => __('forms.labels.credits'),
        };

        $side = match ($bucket) {
            'client', 'cost_in' => __('forms.labels.group_side_receivable'),
            'supplier', 'cost_out' => __('forms.labels.group_side_payable'),
            default => null,
        };

        $parts = array_filter([$title, $side]);

        // Estágio de nível documento soma o valor CHEIO das PIs, não a fatia
        // deste embarque — o cabeçalho avisa, como o badge da linha.
        if (in_array($bucket, ['client', 'supplier'], true) && $this->isDocumentLevelRow($record)) {
            $parts[] = self::DOCUMENT_LEVEL_BADGE;
        }

        if (count($this->stageTotals()['currencies']) > 1) {
            $parts[] = (string) $record->currency_code;
        }

        return implode(' · ', $parts);
    }

    /** Soma, quantidade, pago e restante do grupo — é o que aparece com o grupo fechado. */
    protected function stageDescription(PaymentScheduleItem $record): string
    {
        $totals = $this->stageTotals()['groups'][self::stageKey($record)] ?? null;

        if ($totals === null) {
            return '';
        }

        $currency = (string) $record->currency_code;

        return implode(' · ', [
            $currency.' '.Money::format($totals['amount']),
            trans_choice('forms.labels.group_installments_count', $totals['count'], ['count' => $totals['count']]),
            __('forms.labels.paid').' '.$currency.' '.Money::format($totals['paid']),
            __('forms.labels.remaining').' '.$currency.' '.Money::format($totals['remaining']),
        ]);
    }

    /**
     * @return array{groups: array<string, array{amount: int, paid: int, remaining: int, count: int}>, currencies: list<string>}
     */
    protected function stageTotals(): array
    {
        return $this->stageTotalsCache ??= (function () {
            $groups = [];
            $currencies = [];

            foreach ($this->getFilteredTableQuery()->reorder()->get() as $row) {
                $key = self::stageKey($row);
                $groups[$key] ??= ['amount' => 0, 'paid' => 0, 'remaining' => 0, 'count' => 0];
                $groups[$key]['amount'] += (int) $row->amount;
                $groups[$key]['paid'] += (int) $row->paid_amount;
                // Saldo por parcela (o mesmo da linha), não amount − paid do
                // grupo: parcela paga a mais não abate o saldo das outras.
                $groups[$key]['remaining'] += (int) $row->remaining_amount;
                $groups[$key]['count']++;
                $currencies[(string) $row->currency_code] = true;
            }

            return ['groups' => $groups, 'currencies' => array_keys($currencies)];
        })();
    }

    /** @var array{groups: array<string, array{amount: int, paid: int, remaining: int, count: int}>, currencies: list<string>}|null */
    protected ?array $stageTotalsCache = null;

    /**
     * Ordena só por componentes da chave, para as linhas de um grupo ficarem
     * contíguas (sort_order varia entre PIs para o mesmo estágio). Cliente,
     * depois fornecedor, custos e créditos; estágios na ordem do enum.
     */
    protected function orderByStage(Builder $query, string $direction): Builder
    {
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        $po = addslashes(PurchaseOrder::class);

        $bucketRank = 'CASE WHEN is_credit = 1 THEN 4'
            .' WHEN (source_type IS NOT NULL OR due_condition IS NULL) AND (notes LIKE ? OR notes LIKE ?) THEN 3'
            .' WHEN (source_type IS NOT NULL OR due_condition IS NULL) THEN 2'
            .' WHEN payable_type = ? THEN 1 ELSE 0 END';

        $stageRank = 'CASE due_condition';
        $stageBindings = [];
        foreach (self::STAGE_CHRONOLOGY as $index => $case) {
            $stageRank .= ' WHEN ? THEN '.$index;
            $stageBindings[] = $case->value;
        }
        $stageRank .= ' ELSE 999 END';

        return $query
            ->orderByRaw($bucketRank.' '.$direction, [
                '%'.PaymentScheduleItem::FORWARDER_PAYABLE_TAG.'%',
                '%'.PaymentScheduleItem::SUPPLIER_PAYABLE_TAG.'%',
                PurchaseOrder::class,
            ])
            ->orderByRaw($stageRank.' '.$direction, $stageBindings)
            ->orderBy('percentage', $direction)
            ->orderBy('currency_code', $direction);
    }

    /** Traduz a chave de volta para SQL (somatórios e seleção por grupo do Filament). */
    protected function scopeToStage(Builder $query, string $key): Builder
    {
        $parts = explode('|', $key);
        $bucket = $parts[0];
        $currency = (string) end($parts);

        $isCost = fn (Builder $q) => $q->where(fn (Builder $c) => $c->whereNotNull('source_type')->orWhereNull('due_condition'));
        $hasPayableTag = fn (Builder $q) => $q->where(fn (Builder $t) => $t
            ->where('notes', 'like', '%'.PaymentScheduleItem::FORWARDER_PAYABLE_TAG.'%')
            ->orWhere('notes', 'like', '%'.PaymentScheduleItem::SUPPLIER_PAYABLE_TAG.'%'));

        $query->where('currency_code', $currency);

        return match ($bucket) {
            'credit' => $query->where('is_credit', true),
            'cost_out' => $hasPayableTag($isCost($query->where('is_credit', false))),
            'cost_in' => $isCost($query->where('is_credit', false))->where(fn (Builder $t) => $t
                ->whereNull('notes')
                ->orWhere(fn (Builder $n) => $n
                    ->where('notes', 'not like', '%'.PaymentScheduleItem::FORWARDER_PAYABLE_TAG.'%')
                    ->where('notes', 'not like', '%'.PaymentScheduleItem::SUPPLIER_PAYABLE_TAG.'%'))),
            default => $query
                ->where('is_credit', false)
                ->whereNull('source_type')
                ->where('due_condition', $parts[2] ?? '')
                ->where('percentage', $parts[1] ?? 0)
                ->when(
                    $bucket === 'supplier',
                    fn (Builder $q) => $q->where('payable_type', PurchaseOrder::class),
                    fn (Builder $q) => $q->where('payable_type', '!=', PurchaseOrder::class),
                ),
        };
    }

    /**
     * Pares (id da PI, due_condition) que este embarque já exibe por espelho.
     * A PI do espelho vem do rótulo — "[SH-… / PI-2026-00017]" — mesma
     * convenção que PaymentScheduleItem::getMirrorPaidAmount() usa.
     *
     * @return list<array{0: int, 1: string}>
     */
    protected function mirroredPiStages(Shipment $shipment): array
    {
        $mirrors = PaymentScheduleItem::query()
            ->where('payable_type', Shipment::class)
            ->where('payable_id', $shipment->id)
            ->whereNull('source_type')
            ->whereNotNull('due_condition')
            ->get(['id', 'label', 'due_condition']);

        $stages = [];
        foreach ($mirrors as $mirror) {
            if (preg_match('#/\s*(PI-[\w-]+)\]#', (string) $mirror->label, $match)) {
                $stages[] = [$match[1], $mirror->getRawOriginal('due_condition')];
            }
        }

        if ($stages === []) {
            return [];
        }

        $piIds = ProformaInvoice::whereIn('reference', array_column($stages, 0))->pluck('id', 'reference');

        return collect($stages)
            ->filter(fn (array $stage) => $piIds->has($stage[0]))
            ->map(fn (array $stage) => [(int) $piIds[$stage[0]], (string) $stage[1]])
            ->values()
            ->all();
    }

    /**
     * Ids of the Proforma Invoices this shipment carries items from.
     *
     * @return array<int, int>
     */
    protected function carriedProformaInvoiceIds(Shipment $shipment): array
    {
        $shipment->loadMissing('items.proformaInvoiceItem');

        return $shipment->items
            ->map(fn ($item) => $item->proformaInvoiceItem?->proforma_invoice_id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
