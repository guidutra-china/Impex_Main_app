<?php

namespace App\Domain\Financial\Services;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\Settings\Enums\CalculationBase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Agrega as parcelas do payment schedule de um embarque por estágio de cobrança
 * (due_condition), somando todas as PIs/POs sem expor o detalhe por documento.
 *
 * Regras (spec 2026-07-24-shipment-payment-summary-design.md):
 * - Só linhas canônicas: payable PI (cliente) / PO (fornecedor). Linhas espelho
 *   payable=Shipment nunca entram (evita dupla contagem).
 * - Estágios document-level (order date etc., shipment_id NULL) entram rateados
 *   pela participação do embarque no valor do documento.
 * - Linhas [remaining] (condição de embarque com shipment_id NULL) ficam fora.
 * - "Pago" vem do accessor paid_amount (alocações aprovadas, inclui espelhos).
 */
class ShipmentPaymentSummaryService
{
    /** Display order: document-level events first, then the shipment lifecycle. */
    public const CONDITION_ORDER = [
        'order_date',
        'po_date',
        'invoice_date',
        'before_production',
        'after_production',
        'before_shipment',
        'shipment_date',
        'bl_date',
        'delivery_date',
    ];

    /** Same paid tolerance used by PaymentScheduleItem::isEffectivelyPaid(). */
    private const PAID_TOLERANCE = 100;

    /**
     * @return array<int, array{currency: string, stages: array<int, array<string, mixed>>, totals: array{amount: int, paid: int, remaining: int, percent_paid: int}}>
     */
    public function forClient(Shipment $shipment): array
    {
        return $this->summarize($shipment, ProformaInvoice::class, null);
    }

    /**
     * @return array<int, array{currency: string, stages: array<int, array<string, mixed>>, totals: array{amount: int, paid: int, remaining: int, percent_paid: int}}>
     */
    public function forSupplier(Shipment $shipment, ?int $supplierCompanyId = null): array
    {
        return $this->summarize($shipment, PurchaseOrder::class, $supplierCompanyId);
    }

    /**
     * Open (unpaid) client amounts per shipment, grouped by currency — used by
     * the portal arrivals widget to forecast payments alongside ETAs.
     *
     * @param  array<int, int>|Collection<int, int>  $shipmentIds
     * @return Collection<int, Collection<string, int>> shipment id => [currency => remaining minor units]
     */
    public function openClientRemainderByShipment(array|Collection $shipmentIds): Collection
    {
        return PaymentScheduleItem::query()
            ->where('payable_type', ProformaInvoice::class)
            ->whereIn('shipment_id', $shipmentIds)
            ->where('is_credit', false)
            ->whereIn('status', [
                PaymentScheduleStatus::PENDING->value,
                PaymentScheduleStatus::DUE->value,
                PaymentScheduleStatus::OVERDUE->value,
            ])
            ->get()
            ->groupBy('shipment_id')
            ->map(fn (Collection $items) => $items
                ->groupBy('currency_code')
                ->map(fn (Collection $currencyItems) => (int) $currencyItems->sum('remaining_amount'))
                ->filter(fn (int $remaining) => $remaining > 0));
    }

    /**
     * Valor deste embarque em cada PI que ele carrega (unidades menores),
     * precificado pelo unit_price da PI. É a chave de rateio das parcelas de
     * nível documento — e o subtotal de mercadoria do extrato do embarque.
     *
     * @return Collection<int, int> PI id => valor embarcado
     */
    public function clientShareByProformaInvoice(Shipment $shipment): Collection
    {
        return $this->shipmentShareByDocument($shipment, ProformaInvoice::class, null);
    }

    /**
     * @param  class-string<ProformaInvoice|PurchaseOrder>  $payableType
     */
    private function summarize(Shipment $shipment, string $payableType, ?int $supplierCompanyId): array
    {
        $shares = $this->shipmentShareByDocument($shipment, $payableType, $supplierCompanyId);

        $entries = $this->shipLinkedEntries($shipment, $payableType, $supplierCompanyId)
            ->concat($this->proratedDocumentLevelEntries($shipment, $payableType, $shares));

        return $entries
            ->groupBy('currency')
            ->map(fn (Collection $currencyEntries, string $currency) => [
                'currency' => $currency,
                'stages' => $this->buildStages($currencyEntries),
                'totals' => $this->buildTotals($currencyEntries),
            ])
            ->values()
            ->all();
    }

    /**
     * Value of this shipment's items per participating document (minor units).
     * Client side prices by PI unit_price; supplier side by PO unit_cost.
     *
     * @return Collection<int, int> document id => shipment share value
     */
    private function shipmentShareByDocument(Shipment $shipment, string $payableType, ?int $supplierCompanyId): Collection
    {
        $shipment->loadMissing(['items.proformaInvoiceItem', 'items.purchaseOrderItem.purchaseOrder']);

        if ($payableType === ProformaInvoice::class) {
            return $shipment->items
                ->filter(fn ($item) => $item->proformaInvoiceItem !== null)
                ->groupBy(fn ($item) => $item->proformaInvoiceItem->proforma_invoice_id)
                ->map(fn (Collection $items) => (int) $items->sum(
                    fn ($item) => $item->quantity * ($item->proformaInvoiceItem->unit_price ?? 0),
                ));
        }

        return $shipment->items
            ->filter(fn ($item) => $item->purchaseOrderItem !== null)
            ->when($supplierCompanyId !== null, fn (Collection $items) => $items->filter(
                fn ($item) => $item->purchaseOrderItem->purchaseOrder?->supplier_company_id === $supplierCompanyId,
            ))
            ->groupBy(fn ($item) => $item->purchaseOrderItem->purchase_order_id)
            ->map(fn (Collection $items) => (int) $items->sum(
                fn ($item) => $item->quantity * ($item->purchaseOrderItem->unit_cost ?? 0),
            ));
    }

    /**
     * Canonical schedule rows carved out for this shipment (shipment_id set).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function shipLinkedEntries(Shipment $shipment, string $payableType, ?int $supplierCompanyId): Collection
    {
        $query = PaymentScheduleItem::query()
            ->where('payable_type', $payableType)
            ->where('shipment_id', $shipment->id)
            ->where('is_credit', false);

        // Supplier Portal scoping: only that supplier's POs.
        if ($payableType === PurchaseOrder::class && $supplierCompanyId !== null) {
            $query->whereIn(
                'payable_id',
                PurchaseOrder::query()
                    ->where('supplier_company_id', $supplierCompanyId)
                    ->pluck('id'),
            );
        }

        return $query->get()->map(function (PaymentScheduleItem $item) {
            $paid = min($item->paid_amount, $item->amount);

            return [
                'condition' => $item->due_condition?->value,
                'percentage' => $item->percentage,
                'amount' => (int) $item->amount,
                'paid' => $paid,
                'due_date' => $item->due_date,
                'currency' => $item->currency_code,
                'prorated' => false,
            ];
        });
    }

    /**
     * Document-level stages (charged once per PI/PO) prorated to this
     * shipment's slice of the document value. [remaining] rows — shipment
     * conditions with no shipment link — are intentionally excluded.
     *
     * @param  Collection<int, int>  $shares  document id => shipment share value
     * @return Collection<int, array<string, mixed>>
     */
    private function proratedDocumentLevelEntries(Shipment $shipment, string $payableType, Collection $shares): Collection
    {
        if ($shares->isEmpty()) {
            return collect();
        }

        return PaymentScheduleItem::query()
            ->where('payable_type', $payableType)
            ->whereIn('payable_id', $shares->keys())
            ->whereNull('shipment_id')
            ->where('is_credit', false)
            ->whereIn('due_condition', CalculationBase::documentLevelValues())
            ->get()
            ->map(function (PaymentScheduleItem $item) use ($shares) {
                $share = (int) $shares->get($item->payable_id, 0);

                if ($share <= 0) {
                    return null;
                }

                $sliceAmount = $item->percentage
                    ? (int) round($share * $item->percentage / 100)
                    : 0;

                if ($sliceAmount <= 0) {
                    return null;
                }

                $paidRatio = $item->amount > 0
                    ? min(1, $item->paid_amount / $item->amount)
                    : 0;

                return [
                    'condition' => $item->due_condition?->value,
                    'percentage' => $item->percentage,
                    'amount' => $sliceAmount,
                    'paid' => (int) round($sliceAmount * $paidRatio),
                    'due_date' => $item->due_date,
                    'currency' => $item->currency_code,
                    'prorated' => true,
                ];
            })
            ->filter();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return array<int, array<string, mixed>>
     */
    private function buildStages(Collection $entries): array
    {
        $sectionTotal = (int) $entries->sum('amount');

        return $entries
            ->groupBy('condition')
            ->map(function (Collection $group, ?string $condition) use ($sectionTotal) {
                $amount = (int) $group->sum('amount');
                $paid = (int) $group->sum('paid');
                $remaining = max(0, $amount - $paid);

                $percentages = $group->pluck('percentage')->filter()->unique();

                $openDueDates = $group
                    ->filter(fn (array $entry) => $entry['amount'] - $entry['paid'] > self::PAID_TOLERANCE)
                    ->pluck('due_date')
                    ->filter();

                return [
                    'condition' => $condition,
                    'nominal_percentage' => $percentages->count() === 1 ? (int) $percentages->first() : null,
                    'effective_percentage' => $sectionTotal > 0 ? (int) round($amount / $sectionTotal * 100) : 0,
                    'amount' => $amount,
                    'paid' => $paid,
                    'remaining' => $remaining,
                    'status' => $this->deriveStatus($amount, $paid, $remaining, $openDueDates),
                    'next_due_date' => $openDueDates->sort()->first(),
                    'prorated' => $group->contains(fn (array $entry) => $entry['prorated']),
                ];
            })
            ->sortBy(fn (array $stage) => array_search($stage['condition'], self::CONDITION_ORDER) === false
                ? count(self::CONDITION_ORDER)
                : array_search($stage['condition'], self::CONDITION_ORDER))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, mixed>  $openDueDates
     */
    private function deriveStatus(int $amount, int $paid, int $remaining, Collection $openDueDates): string
    {
        if ($amount > 0 && $remaining <= self::PAID_TOLERANCE) {
            return 'paid';
        }

        if ($paid > self::PAID_TOLERANCE) {
            return 'partial';
        }

        $today = CarbonImmutable::now()->startOfDay();
        $overdue = $openDueDates->contains(
            fn ($date) => $date !== null && CarbonImmutable::parse($date)->startOfDay()->lt($today),
        );

        return $overdue ? 'overdue' : 'pending';
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return array{amount: int, paid: int, remaining: int, percent_paid: int}
     */
    private function buildTotals(Collection $entries): array
    {
        $amount = (int) $entries->sum('amount');
        $paid = (int) $entries->sum('paid');

        return [
            'amount' => $amount,
            'paid' => $paid,
            'remaining' => max(0, $amount - $paid),
            'percent_paid' => $amount > 0 ? (int) round($paid / $amount * 100) : 0,
        ];
    }
}
