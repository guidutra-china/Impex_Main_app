<?php

namespace App\Domain\Infrastructure\Pdf\Templates;

use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Financial\Services\ShipmentPaymentSummaryService;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\Settings\Enums\CalculationBase;
use Illuminate\Support\Collection;

/**
 * Extrato financeiro do embarque — documento de CLIENTE.
 *
 * Nenhum número de custo nosso, custo landed ou margem pode entrar neste
 * payload. Análise interna é o widget LandedCostCalculator; este documento sai
 * para fora da empresa.
 *
 * Spec: docs/superpowers/specs/2026-08-31-shipment-financial-statement-design.md
 */
class ShipmentFinancialStatementPdfTemplate extends AbstractPdfTemplate
{
    public function getView(): string
    {
        return 'pdf.shipment-financial-statement';
    }

    public function getDocumentTitle(): string
    {
        return 'Financial Statement';
    }

    public function getDocumentType(): string
    {
        return 'shipment_financial_statement_pdf';
    }

    public function getFilename(): string
    {
        $reference = $this->model->reference ?? $this->model->getKey();

        return "FS-{$reference}.pdf";
    }

    protected function getDocumentData(): array
    {
        /** @var Shipment $shipment */
        $shipment = $this->model;
        $shipment->loadMissing([
            'company',
            'items.proformaInvoiceItem.proformaInvoice',
            'additionalCosts',
        ]);

        $currencyCode = $shipment->currency_code ?? 'USD';

        $shares = app(ShipmentPaymentSummaryService::class)
            ->clientShareByProformaInvoice($shipment);

        $proformaInvoices = ProformaInvoice::query()
            ->whereIn('id', $shares->keys())
            ->orderBy('reference')
            ->get();

        $goods = $this->buildGoods($proformaInvoices, $shares, $currencyCode);
        $goodsTotal = (int) collect($goods)->where('in_totals', true)->sum('raw_amount');

        $costs = $this->buildCosts($shipment, $currencyCode);
        $costsTotal = (int) collect($costs)->sum('raw_amount');

        $scheduleItems = $this->collectScheduleItems($shipment, $shares);
        $schedule = $this->buildSchedule($scheduleItems, $shares, $currencyCode);
        $scheduleTotal = (int) collect($schedule)->sum('raw_shipment_amount');

        $payments = $this->buildPayments($scheduleItems->pluck('id'), $currencyCode);
        $paidTotal = (int) collect($payments)->sum('raw_amount');

        return [
            'shipment' => $this->buildShipmentBlock($shipment, $currencyCode),
            'client' => ['name' => $shipment->company?->name ?? '—'],
            'goods' => $goods,
            'goods_total' => $this->formatMoney($goodsTotal, $currencyCode, 2),
            'raw_goods_total' => $goodsTotal,
            'has_foreign_currency_pis' => collect($goods)->contains(fn (array $row) => ! $row['in_totals']),
            'costs' => $costs,
            'costs_total' => $this->formatMoney($costsTotal, $currencyCode, 2),
            'raw_costs_total' => $costsTotal,
            'schedule' => $schedule,
            'schedule_total' => $this->formatMoney($scheduleTotal, $currencyCode, 2),
            'raw_schedule_total' => $scheduleTotal,
            'payments' => $payments,
            'raw_paid_total' => $paidTotal,
            'generated_at' => now()->format('d/m/Y H:i'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildShipmentBlock(Shipment $shipment, string $currencyCode): array
    {
        return [
            'reference' => $shipment->reference,
            'client_reference' => $shipment->client_reference ?: '—',
            'issue_date' => $this->formatDate($shipment->issue_date),
            'currency_code' => $currencyCode,
            'transport_mode' => $shipment->transport_mode?->getLabel() ?? '—',
            'incoterm' => $shipment->incoterm?->value ?? '—',
            'bl_number' => $shipment->bl_number ?: '—',
            'vessel' => $shipment->vessel_name ?: '—',
            'voyage' => $shipment->voyage_number ?: '—',
            'origin_port' => $shipment->origin_port ?: '—',
            'destination_port' => $shipment->destination_port ?: '—',
            'etd' => $this->formatDate($shipment->etd),
            'eta' => $this->formatDate($shipment->eta),
            'packages' => $shipment->total_packages !== null ? (string) $shipment->total_packages : '—',
            'gross_weight' => $shipment->total_gross_weight !== null
                ? number_format((float) $shipment->total_gross_weight, 3).' kg'
                : '—',
            'volume' => $shipment->total_volume !== null
                ? number_format((float) $shipment->total_volume, 4).' CBM'
                : '—',
        ];
    }

    /**
     * Custos do embarque repassados ao cliente. Custos absorvidos pela empresa
     * ou repassados ao fornecedor jamais entram — é documento de cliente.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildCosts(Shipment $shipment, string $currencyCode): array
    {
        return $shipment->additionalCosts
            ->filter(fn (AdditionalCost $cost) => $cost->billable_to === BillableTo::CLIENT
                && $cost->status !== AdditionalCostStatus::WAIVED)
            ->sortBy('cost_date')
            ->values()
            ->map(function (AdditionalCost $cost, int $index) use ($currencyCode) {
                $amount = (int) ($cost->amount_in_document_currency ?? $cost->amount);

                return [
                    'index' => $index + 1,
                    'type' => $cost->cost_type->getEnglishLabel(),
                    'description' => $cost->description ?: '—',
                    'date' => $this->formatDate($cost->cost_date),
                    'amount' => $this->formatMoney($amount, $currencyCode, 2),
                    'raw_amount' => $amount,
                ];
            })
            ->all();
    }

    /**
     * As parcelas que este embarque cobra do cliente, em três baldes:
     * ship-specific da PI, nível documento da PI (rateado) e custo repassado
     * do próprio embarque. Linhas [remaining] — condição de embarque sem
     * vínculo — são o saldo não embarcado e ficam fora.
     *
     * @param  Collection<int, int>  $shares
     * @return Collection<int, PaymentScheduleItem>
     */
    private function collectScheduleItems(Shipment $shipment, Collection $shares): Collection
    {
        $shipSpecific = PaymentScheduleItem::query()
            ->where('payable_type', ProformaInvoice::class)
            ->where('shipment_id', $shipment->id)
            ->whereNull('source_type')
            ->where('is_credit', false)
            ->get();

        $documentLevel = $shares->isEmpty()
            ? collect()
            : PaymentScheduleItem::query()
                ->where('payable_type', ProformaInvoice::class)
                ->whereIn('payable_id', $shares->keys())
                ->whereNull('shipment_id')
                ->whereNull('source_type')
                ->where('is_credit', false)
                ->whereIn('due_condition', CalculationBase::documentLevelValues())
                ->get();

        $shipmentCosts = PaymentScheduleItem::query()
            ->where('payable_type', Shipment::class)
            ->where('payable_id', $shipment->id)
            ->where('source_type', AdditionalCost::class)
            ->where('is_credit', false)
            ->withoutSideTags()
            ->whereIn(
                'source_id',
                AdditionalCost::query()
                    ->where('billable_to', BillableTo::CLIENT)
                    ->where('status', '!=', AdditionalCostStatus::WAIVED)
                    ->select('id'),
            )
            ->get();

        return $shipSpecific
            ->concat($documentLevel)
            ->concat($shipmentCosts)
            ->sortBy('sort_order')
            ->values();
    }

    /**
     * @param  Collection<int, PaymentScheduleItem>  $items
     * @param  Collection<int, int>  $shares
     * @return array<int, array<string, mixed>>
     */
    private function buildSchedule(Collection $items, Collection $shares, string $currencyCode): array
    {
        $references = ProformaInvoice::query()
            ->whereIn('id', $shares->keys())
            ->pluck('reference', 'id');

        return $items
            ->map(function (PaymentScheduleItem $item) use ($shares, $references, $currencyCode) {
                $documentAmount = (int) $item->amount;
                $isDocumentLevel = $item->payable_type === ProformaInvoice::class
                    && $item->shipment_id === null;

                if ($isDocumentLevel) {
                    $share = (int) $shares->get($item->payable_id, 0);
                    $shipmentAmount = $item->percentage
                        ? (int) round($share * $item->percentage / 100)
                        : 0;
                } else {
                    $shipmentAmount = $documentAmount;
                }

                if ($shipmentAmount <= 0) {
                    return null;
                }

                $ratio = $documentAmount > 0 ? $shipmentAmount / $documentAmount : 1;
                $paid = (int) round(min($item->paid_amount, $documentAmount) * $ratio);
                $balance = max(0, $shipmentAmount - $paid);

                return [
                    'label' => $item->label ?? '—',
                    'document' => $item->payable_type === ProformaInvoice::class
                        ? ($references->get($item->payable_id) ?? '—')
                        : '—',
                    'due_date' => $item->due_date ? $this->formatDate($item->due_date) : '—',
                    'condition' => $item->due_condition?->value,
                    'document_amount' => $this->formatMoney($documentAmount, $currencyCode, 2),
                    'raw_document_amount' => $documentAmount,
                    'shipment_amount' => $this->formatMoney($shipmentAmount, $currencyCode, 2),
                    'raw_shipment_amount' => $shipmentAmount,
                    'paid' => $this->formatMoney($paid, $currencyCode, 2),
                    'raw_paid' => $paid,
                    'balance' => $this->formatMoney($balance, $currencyCode, 2),
                    'raw_balance' => $balance,
                    'status' => $item->status->getEnglishLabel(),
                    'status_value' => $item->status->value,
                    'is_prorated' => $isDocumentLevel && $shipmentAmount !== $documentAmount,
                    'share_percent' => $documentAmount > 0
                        ? (int) round($shipmentAmount / $documentAmount * 100)
                        : 100,
                    'is_overdue' => $item->due_date?->isPast() === true
                        && ! $item->status->isResolved(),
                ];
            })
            ->filter()
            ->values()
            ->map(fn (array $row, int $index) => ['index' => $index + 1] + $row)
            ->all();
    }

    /**
     * Pagamentos em dinheiro já recebidos e alocados às parcelas deste
     * extrato. Alocações de crédito (credit_schedule_item_id preenchido) não
     * entram — Credit Notes estão fora do escopo deste documento.
     *
     * @param  Collection<int, int>  $scheduleItemIds
     * @return array<int, array<string, mixed>>
     */
    private function buildPayments(Collection $scheduleItemIds, string $currencyCode): array
    {
        if ($scheduleItemIds->isEmpty()) {
            return [];
        }

        return PaymentAllocation::query()
            ->whereIn('payment_schedule_item_id', $scheduleItemIds)
            ->whereNull('credit_schedule_item_id')
            ->whereHas('payment', fn ($q) => $q->where('status', PaymentStatus::APPROVED))
            ->with(['payment.paymentMethod', 'scheduleItem'])
            ->get()
            ->sortBy(fn (PaymentAllocation $a) => [$a->payment->payment_date?->timestamp ?? 0, $a->id])
            ->values()
            ->map(function (PaymentAllocation $allocation, int $index) use ($currencyCode) {
                $amount = (int) $allocation->allocated_amount_in_document_currency;

                return [
                    'index' => $index + 1,
                    'date' => $this->formatDate($allocation->payment->payment_date),
                    'reference' => $allocation->payment->reference ?: '—',
                    'method' => $allocation->payment->paymentMethod?->name ?? '—',
                    'applied_to' => $allocation->scheduleItem?->label ?? '—',
                    'amount' => $this->formatMoney($amount, $currencyCode, 2),
                    'raw_amount' => $amount,
                ];
            })
            ->all();
    }

    /**
     * @param  Collection<int, ProformaInvoice>  $proformaInvoices
     * @param  Collection<int, int>  $shares
     * @return array<int, array<string, mixed>>
     */
    private function buildGoods(Collection $proformaInvoices, Collection $shares, string $currencyCode): array
    {
        return $proformaInvoices->values()->map(function (ProformaInvoice $pi, int $index) use ($shares, $currencyCode) {
            $amount = (int) $shares->get($pi->id, 0);
            $piCurrency = $pi->currency_code ?? $currencyCode;

            return [
                'index' => $index + 1,
                'reference' => $pi->reference,
                'client_reference' => $pi->client_reference ?: '—',
                'currency_code' => $piCurrency,
                'amount' => $this->formatMoney($amount, $piCurrency, 2),
                'raw_amount' => $amount,
                'in_totals' => $piCurrency === $currencyCode,
            ];
        })->all();
    }
}
