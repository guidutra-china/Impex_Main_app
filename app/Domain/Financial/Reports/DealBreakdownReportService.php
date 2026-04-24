<?php

namespace App\Domain\Financial\Reports;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Reports\DTOs\AdditionalCostRow;
use App\Domain\Financial\Reports\DTOs\DealBreakdownFilters;
use App\Domain\Financial\Reports\DTOs\DealBreakdownReport;
use App\Domain\Financial\Reports\DTOs\DealRow;
use App\Domain\Financial\Reports\DTOs\DealTotals;
use App\Domain\Financial\Reports\DTOs\KpiSummary;
use App\Domain\Financial\Reports\DTOs\PiInfo;
use App\Domain\Financial\Reports\DTOs\PoRow;
use App\Domain\Financial\Reports\DTOs\ReceiptItem;
use App\Domain\Financial\Reports\DTOs\ReceiptsBlock;
use App\Domain\Financial\Reports\DTOs\ShipmentAttributionRow;
use App\Domain\Financial\Reports\Support\FxConverter;
use App\Domain\Financial\Reports\Support\ShipmentAttributionCalculator;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Filament\Resources\ProformaInvoices\ProformaInvoiceResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\Shipments\ShipmentResource;
use Carbon\CarbonImmutable;

final class DealBreakdownReportService
{
    public function __construct(
        private readonly ShipmentAttributionCalculator $attributor = new ShipmentAttributionCalculator(),
    ) {
    }

    public function build(Company $client, DealBreakdownFilters $filters): DealBreakdownReport
    {
        $scopeIds = $this->resolveScopeIds($client);

        $pis = ProformaInvoice::query()
            ->whereIn('company_id', $scopeIds)
            ->whereIn('status', $filters->statusValues())
            ->whereBetween('issue_date', [$filters->from->toDateString(), $filters->to->toDateString()])
            ->with([
                'items',
                'paymentScheduleItems.allocations.payment',
                'paymentScheduleItems.paymentTermStage',
                'purchaseOrders.items',
                'purchaseOrders.supplierCompany:id,name',
                'purchaseOrders.paymentScheduleItems.allocations.payment',
                'items.shipmentItems.shipment.items.proformaInvoiceItem',
                'items.shipmentItems.shipment.forwarderCompany:id,name',
                'items.shipmentItems.shipment.paymentScheduleItems.allocations.payment',
                'items.shipmentItems.shipment.additionalCosts',
            ])
            ->orderByDesc('issue_date')
            ->get();

        $fxCache = $this->prefetchFxCache($pis, $filters->presentationCurrency);
        $fx = new FxConverter($filters->presentationCurrency, $fxCache);
        $unconverted = [];

        $deals = [];
        foreach ($pis as $pi) {
            $deals[] = $this->buildDealRow($pi, $fx, $unconverted);
        }

        return new DealBreakdownReport(
            clientId: $client->id,
            clientName: (string) $client->name,
            presentationCurrency: $filters->presentationCurrency,
            filters: $filters,
            kpi: $this->buildKpi($deals),
            deals: $deals,
            unconvertedCurrencyPairs: array_values(array_unique($unconverted)),
        );
    }

    /** @return list<int> */
    private function resolveScopeIds(Company $client): array
    {
        $ids = [$client->id];
        $children = Company::query()
            ->where('parent_company_id', $client->id)
            ->pluck('id')
            ->all();
        return array_values(array_unique(array_merge($ids, $children)));
    }

    private function buildDealRow(ProformaInvoice $pi, FxConverter $fx, array &$unconverted): DealRow
    {
        $piIssue = CarbonImmutable::parse((string) $pi->issue_date);
        $piTotalOriginal = (int) $pi->items->sum(fn ($i) => $i->quantity * $i->unit_price);
        $piTotalPres = $fx->convertDocument($piTotalOriginal, (string) $pi->currency_code, $piIssue);
        $this->recordMissing($piTotalPres, (string) $pi->currency_code, $unconverted);

        $piInfo = new PiInfo(
            id: $pi->id,
            reference: (string) $pi->reference,
            clientReference: ($pi->client_reference !== null && $pi->client_reference !== '') ? $pi->client_reference : null,
            issueDate: $piIssue,
            status: $pi->status,
            currencyOriginal: (string) $pi->currency_code,
            totalOriginal: $piTotalOriginal,
            totalPresentation: $piTotalPres,
            detailUrl: ProformaInvoiceResource::getUrl('view', ['record' => $pi->id]),
        );

        $receipts = $this->buildReceipts($pi, $fx, $piTotalOriginal, $piTotalPres);
        $poRows = $this->buildPoRows($pi, $fx, $unconverted);
        $shipmentRows = $this->buildShipmentRows($pi, $fx, $unconverted);

        $paidSuppliers = array_sum(array_map(fn ($p) => (int) ($p->paidPresentation ?? 0), $poRows));
        $paidShipments = array_sum(array_map(fn ($s) => (int) ($s->paidPresentation ?? 0), $shipmentRows));
        $received = (int) ($receipts->paidPresentation ?? 0);
        $cashBalance = $received - $paidSuppliers - $paidShipments;

        $poTotalPres = array_sum(array_map(fn ($p) => (int) ($p->totalPresentation ?? 0), $poRows));
        $shipAttribPres = array_sum(array_map(fn ($s) => (int) ($s->attributedPresentation ?? 0), $shipmentRows));
        $margin = (int) ($piTotalPres ?? 0) - $poTotalPres - $shipAttribPres;
        $marginPct = ($poTotalPres + $shipAttribPres) > 0
            ? (float) round($margin / ($poTotalPres + $shipAttribPres) * 100, 1)
            : 0.0;

        return new DealRow(
            pi: $piInfo,
            receipts: $receipts,
            purchaseOrders: $poRows,
            shipments: $shipmentRows,
            totals: new DealTotals(
                cashBalance: $cashBalance,
                margin: $margin,
                marginPct: $marginPct,
            ),
        );
    }

    private function buildReceipts(ProformaInvoice $pi, FxConverter $fx, int $piTotalOriginal, ?int $piTotalPres): ReceiptsBlock
    {
        $paidOriginal = 0;
        $paidPresentation = 0;
        $hasMissing = false;
        $items = [];

        foreach ($pi->paymentScheduleItems as $scheduleItem) {
            foreach ($scheduleItem->allocations as $alloc) {
                if ($alloc->payment?->status !== PaymentStatus::APPROVED) {
                    continue;
                }
                $amt = (int) $alloc->allocated_amount_in_document_currency;
                $paidOriginal += $amt;

                $pres = $fx->convertPayment($alloc);
                if ($pres === null) {
                    $hasMissing = true;
                } else {
                    $paidPresentation += $pres;
                }

                $items[] = new ReceiptItem(
                    paymentDate: CarbonImmutable::parse((string) $alloc->payment->payment_date),
                    paymentReference: (string) $alloc->payment->reference,
                    stageLabel: $scheduleItem->paymentTermStage?->label ?? $scheduleItem->label,
                    amountOriginal: $amt,
                    amountPresentation: $pres,
                    exchangeRateToPresentation: null,
                    paymentUrl: '#',
                );
            }
        }

        $outstandingOriginal = max(0, $piTotalOriginal - $paidOriginal);
        $outstandingPres = ($piTotalPres !== null && ! $hasMissing)
            ? max(0, $piTotalPres - $paidPresentation)
            : null;

        return new ReceiptsBlock(
            paidOriginal: $paidOriginal,
            paidPresentation: $hasMissing ? null : $paidPresentation,
            outstandingOriginal: $outstandingOriginal,
            outstandingPresentation: $outstandingPres,
            percentPaid: $piTotalOriginal > 0 ? round($paidOriginal / $piTotalOriginal * 100, 1) : 0.0,
            items: $items,
        );
    }

    /** @return list<PoRow> */
    private function buildPoRows(ProformaInvoice $pi, FxConverter $fx, array &$unconverted): array
    {
        $rows = [];
        foreach ($pi->purchaseOrders as $po) {
            $totalOriginal = (int) $po->items->sum(fn ($i) => $i->quantity * $i->unit_price);
            $paidOriginal = 0;
            $paidPres = 0;
            $hasMissing = false;

            foreach ($po->paymentScheduleItems as $scheduleItem) {
                foreach ($scheduleItem->allocations as $alloc) {
                    if ($alloc->payment?->status !== PaymentStatus::APPROVED) {
                        continue;
                    }
                    $paidOriginal += (int) $alloc->allocated_amount_in_document_currency;
                    $pres = $fx->convertPayment($alloc);
                    if ($pres === null) {
                        $hasMissing = true;
                    } else {
                        $paidPres += $pres;
                    }
                }
            }

            $issueDate = CarbonImmutable::parse((string) $po->issue_date);
            $totalPres = $fx->convertDocument($totalOriginal, (string) $po->currency_code, $issueDate);
            $this->recordMissing($totalPres, (string) $po->currency_code, $unconverted);

            $outstandingOriginal = max(0, $totalOriginal - $paidOriginal);
            $outstandingPres = ($totalPres !== null && ! $hasMissing)
                ? max(0, $totalPres - $paidPres)
                : null;

            $rows[] = new PoRow(
                id: $po->id,
                reference: (string) $po->reference,
                supplierName: (string) ($po->supplierCompany?->name ?? ''),
                currencyOriginal: (string) $po->currency_code,
                totalOriginal: $totalOriginal,
                totalPresentation: $totalPres,
                paidOriginal: $paidOriginal,
                paidPresentation: $hasMissing ? null : $paidPres,
                outstandingOriginal: $outstandingOriginal,
                outstandingPresentation: $outstandingPres,
                status: $po->status,
                detailUrl: PurchaseOrderResource::getUrl('view', ['record' => $po->id]),
            );
        }
        return $rows;
    }

    /** @return list<ShipmentAttributionRow> */
    private function buildShipmentRows(ProformaInvoice $pi, FxConverter $fx, array &$unconverted): array
    {
        $shipments = collect();
        foreach ($pi->items as $piItem) {
            foreach ($piItem->shipmentItems ?? [] as $si) {
                if ($si->shipment) {
                    $shipments->put($si->shipment->id, $si->shipment);
                }
            }
        }

        $rows = [];
        foreach ($shipments as $shipment) {
            $attribution = $this->attributor->calculate($shipment, $pi);

            $scheduleTotal = (int) $shipment->paymentScheduleItems->sum('amount');
            $additionalTotal = (int) $shipment->additionalCosts->sum('amount_in_document_currency');
            $totalCostOriginal = $scheduleTotal + $additionalTotal;
            $attributedOriginal = (int) round($totalCostOriginal * $attribution->pct);

            $paidOriginalFull = 0;
            $paidPresFull = 0;
            $hasMissing = false;
            foreach ($shipment->paymentScheduleItems as $scheduleItem) {
                foreach ($scheduleItem->allocations as $alloc) {
                    if ($alloc->payment?->status !== PaymentStatus::APPROVED) {
                        continue;
                    }
                    $paidOriginalFull += (int) $alloc->allocated_amount_in_document_currency;
                    $pres = $fx->convertPayment($alloc);
                    if ($pres === null) {
                        $hasMissing = true;
                    } else {
                        $paidPresFull += $pres;
                    }
                }
            }
            $paidOriginalAttrib = (int) round($paidOriginalFull * $attribution->pct);
            $paidPresAttrib = $hasMissing ? null : (int) round($paidPresFull * $attribution->pct);

            $shipmentIssue = CarbonImmutable::parse((string) $shipment->issue_date);
            $attributedPres = $fx->convertDocument($attributedOriginal, (string) $shipment->currency_code, $shipmentIssue);
            $this->recordMissing($attributedPres, (string) $shipment->currency_code, $unconverted);

            $additionalCostRows = [];
            foreach ($shipment->additionalCosts as $cost) {
                $costTotal = (int) $cost->amount_in_document_currency;
                $attrib = (int) round($costTotal * $attribution->pct);
                $attribPres = $fx->convertDocument($attrib, (string) $shipment->currency_code, $shipmentIssue);
                $additionalCostRows[] = new AdditionalCostRow(
                    label: (string) ($cost->description ?? $cost->cost_type?->getLabel() ?? ''),
                    type: $cost->cost_type,
                    totalOriginal: $costTotal,
                    attributedOriginal: $attrib,
                    attributedPresentation: $attribPres,
                );
            }

            $rows[] = new ShipmentAttributionRow(
                id: $shipment->id,
                reference: (string) $shipment->reference,
                clientReference: ($shipment->client_reference !== null && $shipment->client_reference !== '') ? $shipment->client_reference : null,
                forwarderName: $shipment->forwarderCompany?->name ?? $shipment->freight_forwarder,
                currencyOriginal: (string) $shipment->currency_code,
                totalCostOriginal: $totalCostOriginal,
                attributionPct: $attribution->pct,
                basis: $attribution->basis,
                attributedOriginal: $attributedOriginal,
                attributedPresentation: $attributedPres,
                paidOriginal: $paidOriginalAttrib,
                paidPresentation: $paidPresAttrib,
                outstandingOriginal: max(0, $attributedOriginal - $paidOriginalAttrib),
                outstandingPresentation: ($attributedPres !== null && $paidPresAttrib !== null)
                    ? max(0, $attributedPres - $paidPresAttrib)
                    : null,
                detailUrl: ShipmentResource::getUrl('view', ['record' => $shipment->id]),
                additionalCosts: $additionalCostRows,
            );
        }
        return $rows;
    }

    /** @param  list<DealRow>  $deals */
    private function buildKpi(array $deals): KpiSummary
    {
        $received = 0;
        $paidSuppliers = 0;
        $paidShipments = 0;
        $margin = 0;

        foreach ($deals as $deal) {
            $received += (int) ($deal->receipts->paidPresentation ?? 0);
            foreach ($deal->purchaseOrders as $po) {
                $paidSuppliers += (int) ($po->paidPresentation ?? 0);
            }
            foreach ($deal->shipments as $sh) {
                $paidShipments += (int) ($sh->paidPresentation ?? 0);
            }
            $margin += $deal->totals->margin;
        }

        return new KpiSummary(
            totalReceived: $received,
            totalPaidSuppliers: $paidSuppliers,
            totalPaidShipments: $paidShipments,
            totalMargin: $margin,
            dealCount: count($deals),
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ProformaInvoice>  $pis
     * @return array<string, float>
     */
    private function prefetchFxCache($pis, string $presentationCurrency): array
    {
        $needed = [];
        $add = function (?string $currency, ?string $date) use (&$needed) {
            if ($currency !== null && $currency !== '' && $date !== null && $date !== '') {
                $needed[] = ['from' => $currency, 'at' => CarbonImmutable::parse($date)];
            }
        };

        foreach ($pis as $pi) {
            $add($pi->currency_code, (string) $pi->issue_date);
            foreach ($pi->paymentScheduleItems as $si) {
                foreach ($si->allocations as $a) {
                    $add($si->currency_code ?? $pi->currency_code, $a->payment?->payment_date ? (string) $a->payment->payment_date : null);
                }
            }
            foreach ($pi->purchaseOrders as $po) {
                $add($po->currency_code, (string) $po->issue_date);
                foreach ($po->paymentScheduleItems as $si) {
                    foreach ($si->allocations as $a) {
                        $add($si->currency_code ?? $po->currency_code, $a->payment?->payment_date ? (string) $a->payment->payment_date : null);
                    }
                }
            }
            foreach ($pi->items as $piItem) {
                foreach ($piItem->shipmentItems ?? [] as $si) {
                    if ($si->shipment) {
                        $add($si->shipment->currency_code, (string) $si->shipment->issue_date);
                        foreach ($si->shipment->paymentScheduleItems as $schedule) {
                            foreach ($schedule->allocations as $a) {
                                $add($schedule->currency_code ?? $si->shipment->currency_code, $a->payment?->payment_date ? (string) $a->payment->payment_date : null);
                            }
                        }
                    }
                }
            }
        }

        return FxConverter::prefetchCache($needed, $presentationCurrency);
    }

    private function recordMissing(?int $pres, string $currency, array &$unconverted): void
    {
        if ($pres === null && $currency !== '') {
            $unconverted[] = $currency;
        }
    }
}
