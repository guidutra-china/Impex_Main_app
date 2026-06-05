<?php

namespace App\Domain\Financial\Reports;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\DebitNote;
use App\Domain\Financial\Reports\DTOs\AdditionalCostRow;
use App\Domain\Financial\Reports\DTOs\CommissionBlock;
use App\Domain\Financial\Reports\DTOs\DealBreakdownFilters;
use App\Domain\Financial\Reports\DTOs\DealBreakdownReport;
use App\Domain\Financial\Reports\DTOs\DealRow;
use App\Domain\Financial\Reports\DTOs\DealTotals;
use App\Domain\Financial\Reports\DTOs\DebitNoteRow;
use App\Domain\Financial\Reports\DTOs\KpiSummary;
use App\Domain\Financial\Reports\DTOs\PiInfo;
use App\Domain\Financial\Reports\DTOs\PoRow;
use App\Domain\Financial\Reports\DTOs\ReceiptItem;
use App\Domain\Financial\Reports\DTOs\ReceiptsBlock;
use App\Domain\Financial\Reports\DTOs\ShipmentAttributionRow;
use App\Domain\Financial\Reports\Support\FxConverter;
use App\Domain\Financial\Reports\Support\ShipmentAttributionCalculator;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\Quotations\Enums\CommissionType;
use App\Filament\Resources\Finance\DebitNotes\DebitNoteResource;
use App\Filament\Resources\ProformaInvoices\ProformaInvoiceResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\Shipments\ShipmentResource;
use Carbon\CarbonImmutable;

final class DealBreakdownReportService
{
    public function __construct(
        private readonly ShipmentAttributionCalculator $attributor = new ShipmentAttributionCalculator,
    ) {}

    public function build(Company $client, DealBreakdownFilters $filters): DealBreakdownReport
    {
        $scopeIds = $this->resolveScopeIds($client);

        $pis = ProformaInvoice::query()
            ->whereIn('company_id', $scopeIds)
            ->whereIn('status', $filters->statusValues())
            ->whereBetween('issue_date', [$filters->from->toDateString(), $filters->to->toDateString()])
            ->with([
                'items',
                'additionalCosts',
                'quotations.items',
                'paymentScheduleItems.allocations.payment',
                'paymentScheduleItems.allocations.scheduleItem',
                'paymentScheduleItems.paymentTermStage',
                'purchaseOrders.items',
                'purchaseOrders.supplierCompany:id,name',
                'purchaseOrders.paymentScheduleItems.allocations.payment',
                'purchaseOrders.paymentScheduleItems.allocations.scheduleItem',
                'items.shipmentItems.shipment.items.proformaInvoiceItem',
                'items.shipmentItems.shipment.forwarderCompany:id,name',
                'items.shipmentItems.shipment.paymentScheduleItems.allocations.payment',
                'items.shipmentItems.shipment.paymentScheduleItems.allocations.scheduleItem',
                'items.shipmentItems.shipment.additionalCosts',
            ])
            ->orderByDesc('issue_date')
            ->get();

        $fxCache = $this->prefetchFxCache($pis, $scopeIds, $filters, $filters->presentationCurrency);
        $fx = new FxConverter($filters->presentationCurrency, $fxCache);
        $unconverted = [];

        $deals = [];
        foreach ($pis as $pi) {
            $deals[] = $this->buildDealRow($pi, $fx, $unconverted);
        }

        $debitNotes = $this->buildDebitNoteRows($scopeIds, $filters, $fx, $unconverted);

        return new DealBreakdownReport(
            clientId: $client->id,
            clientName: (string) $client->name,
            presentationCurrency: $filters->presentationCurrency,
            filters: $filters,
            kpi: $this->buildKpi($deals, $debitNotes),
            deals: $deals,
            debitNotes: $debitNotes,
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
        // Reembolsos de frete pagos pelo cliente entram como dinheiro recebido,
        // não como "Paid Shipments" (que agora é só saída para forwarders).
        $freightReceived = array_sum(array_map(fn ($s) => (int) ($s->freightReceivedPresentation ?? 0), $shipmentRows));
        $received = (int) ($receipts->paidPresentation ?? 0) + $freightReceived;
        $cashBalance = $received - $paidSuppliers - $paidShipments;

        $poTotalPres = array_sum(array_map(fn ($p) => (int) ($p->totalPresentation ?? 0), $poRows));
        $shipCostPres = array_sum(array_map(fn ($s) => (int) ($s->attributedPresentation ?? 0), $shipmentRows));
        $freightChargePres = array_sum(array_map(fn ($s) => (int) ($s->attributedClientChargePresentation ?? 0), $shipmentRows));
        // Margem = receita PI (mercadoria) + frete cobrado do cliente (receita)
        //          − custo PO (mercadoria) − custo real de frete/logística.
        // Frete contribui com (cobrança ao cliente − custo do forwarder).
        $margin = (int) ($piTotalPres ?? 0) + $freightChargePres - $poTotalPres - $shipCostPres;
        $costBase = $poTotalPres + $shipCostPres;
        $marginPct = $costBase > 0
            ? (float) round($margin / $costBase * 100, 1)
            : 0.0;

        $commission = $this->buildCommission($pi, $fx, $piIssue, $piTotalOriginal, $receipts, $unconverted);

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
            commission: $commission,
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
                    paymentUrl: null,
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
            // PurchaseOrderItem stores cost as `unit_cost` (not `unit_price`).
            $totalOriginal = (int) $po->items->sum(fn ($i) => $i->quantity * $i->unit_cost);
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
            $shipmentIssue = CarbonImmutable::parse((string) $shipment->issue_date);
            $shipmentCurrency = (string) $shipment->currency_code;

            // Custo REAL para a Impex vs. valor cobrado do cliente (receita).
            // Para cada AdditionalCost da shipment:
            //  - custo real = forwarder_amount_in_document_currency quando há repasse
            //    a forwarder; senão amount_in_document_currency apenas se
            //    billable_to=company (custo interno). Pass-through puro ao cliente
            //    (sem forwarder) = custo 0 — é reembolsado, não é custo da Impex.
            //  - cobrança ao cliente = amount_in_document_currency quando billable_to=client.
            $realCostOriginal = 0;
            $clientChargeOriginal = 0;
            $additionalCostRows = [];
            foreach ($shipment->additionalCosts as $cost) {
                $forwarderAmount = (int) ($cost->forwarder_amount_in_document_currency ?? 0);
                $docAmount = (int) $cost->amount_in_document_currency;

                if ($forwarderAmount > 0) {
                    $costReal = $forwarderAmount;
                } elseif ($cost->billable_to === BillableTo::COMPANY) {
                    $costReal = $docAmount;
                } else {
                    $costReal = 0;
                }
                $realCostOriginal += $costReal;

                if ($cost->billable_to === BillableTo::CLIENT) {
                    $clientChargeOriginal += $docAmount;
                }

                $attrib = (int) round($costReal * $attribution->pct);
                $attribPres = $fx->convertDocument($attrib, $shipmentCurrency, $shipmentIssue);
                $additionalCostRows[] = new AdditionalCostRow(
                    label: (string) ($cost->description ?? $cost->cost_type?->getLabel() ?? ''),
                    type: $cost->cost_type,
                    totalOriginal: $costReal,
                    attributedOriginal: $attrib,
                    attributedPresentation: $attribPres,
                );
            }

            $totalCostOriginal = $realCostOriginal;
            $attributedOriginal = (int) round($totalCostOriginal * $attribution->pct);

            // Pagamentos da shipment separados por DIREÇÃO do pagamento. Frete com
            // repasse a forwarder gera dois fluxos: entrada (cliente reembolsa,
            // INBOUND) e saída (Impex paga forwarder, OUTBOUND). Misturá-los
            // distorcia "Paid Shipments" e o Cash Balance.
            $paidOutFull = 0;
            $paidOutPresFull = 0;
            $paidInFull = 0;
            $paidInPresFull = 0;
            $hasMissing = false;
            foreach ($shipment->paymentScheduleItems as $scheduleItem) {
                foreach ($scheduleItem->allocations as $alloc) {
                    if ($alloc->payment?->status !== PaymentStatus::APPROVED) {
                        continue;
                    }
                    $amt = (int) $alloc->allocated_amount_in_document_currency;
                    $pres = $fx->convertPayment($alloc);
                    if ($pres === null) {
                        $hasMissing = true;
                    }
                    if ($alloc->payment->direction === PaymentDirection::INBOUND) {
                        $paidInFull += $amt;
                        if ($pres !== null) {
                            $paidInPresFull += $pres;
                        }
                    } else {
                        $paidOutFull += $amt;
                        if ($pres !== null) {
                            $paidOutPresFull += $pres;
                        }
                    }
                }
            }

            $paidOriginalAttrib = (int) round($paidOutFull * $attribution->pct);
            $paidPresAttrib = $hasMissing ? null : (int) round($paidOutPresFull * $attribution->pct);
            $freightReceivedOriginal = (int) round($paidInFull * $attribution->pct);
            $freightReceivedPres = $hasMissing ? null : (int) round($paidInPresFull * $attribution->pct);

            $attributedPres = $fx->convertDocument($attributedOriginal, $shipmentCurrency, $shipmentIssue);
            $this->recordMissing($attributedPres, $shipmentCurrency, $unconverted);

            $attributedClientChargeOriginal = (int) round($clientChargeOriginal * $attribution->pct);
            $clientChargePres = $fx->convertDocument($clientChargeOriginal, $shipmentCurrency, $shipmentIssue);
            $attributedClientChargePres = $fx->convertDocument($attributedClientChargeOriginal, $shipmentCurrency, $shipmentIssue);

            $rows[] = new ShipmentAttributionRow(
                id: $shipment->id,
                reference: (string) $shipment->reference,
                clientReference: ($shipment->client_reference !== null && $shipment->client_reference !== '') ? $shipment->client_reference : null,
                forwarderName: $shipment->forwarderCompany?->name ?? $shipment->freight_forwarder,
                currencyOriginal: $shipmentCurrency,
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
                clientChargeOriginal: $clientChargeOriginal,
                clientChargePresentation: $clientChargePres,
                attributedClientChargeOriginal: $attributedClientChargeOriginal,
                attributedClientChargePresentation: $attributedClientChargePres,
                freightReceivedOriginal: $freightReceivedOriginal,
                freightReceivedPresentation: $freightReceivedPres,
            );
        }

        return $rows;
    }

    /**
     * Comissão por deal (PI), em moeda de apresentação.
     *
     * - recebida (cobrada do cliente):
     *     separate = AdditionalCost COMMISSION billable_to=client na PI;
     *     embedded = derivada das quotations ligadas com commission_type=EMBEDDED
     *     (subtotal × rate/100), já embutida no unit_price da PI.
     * - paga (quanto o cliente já pagou):
     *     separate = alocações aprovadas nos PSIs gerados desses custos de comissão;
     *     embedded = proporcional ao pagamento das mercadorias (está no preço da PI).
     * - outstanding = recebida − paga.
     *
     * Custos da PI estão na moeda do documento PI; conversão usa moeda/data da PI.
     */
    private function buildCommission(ProformaInvoice $pi, FxConverter $fx, CarbonImmutable $piIssue, int $piTotalOriginal, ReceiptsBlock $receipts, array &$unconverted): CommissionBlock
    {
        $currency = (string) $pi->currency_code;

        // Comissão cobrada do cliente (a receber).
        $commissionCostIds = [];
        $separateOriginal = 0;
        foreach ($pi->additionalCosts as $cost) {
            if ($cost->cost_type !== AdditionalCostType::COMMISSION) {
                continue;
            }
            if ($cost->billable_to === BillableTo::CLIENT) {
                $separateOriginal += (int) $cost->amount_in_document_currency;
                $commissionCostIds[$cost->id] = true;
            }
        }

        $embeddedOriginal = 0;
        foreach ($pi->quotations as $quotation) {
            if ($quotation->commission_type === CommissionType::EMBEDDED && (float) $quotation->commission_rate > 0) {
                $embeddedOriginal += (int) round($quotation->subtotal * ((float) $quotation->commission_rate / 100));
            }
        }

        // Quanto o cliente já pagou da comissão "separate": alocações aprovadas nos
        // PSIs cuja origem é um AdditionalCost de comissão (billable_to=client).
        $separatePaidOriginal = 0;
        foreach ($pi->paymentScheduleItems as $scheduleItem) {
            if ($scheduleItem->source_type !== AdditionalCost::class || ! isset($commissionCostIds[$scheduleItem->source_id])) {
                continue;
            }
            foreach ($scheduleItem->allocations as $alloc) {
                if ($alloc->payment?->status !== PaymentStatus::APPROVED) {
                    continue;
                }
                $separatePaidOriginal += (int) $alloc->allocated_amount_in_document_currency;
            }
        }

        // Comissão embutida é coletada proporcionalmente ao pagamento das mercadorias.
        // goods_paid = recebido total na PI − parcela de comissão separate já paga.
        $goodsPaidOriginal = max(0, (int) $receipts->paidOriginal - $separatePaidOriginal);
        $embeddedPaidOriginal = ($embeddedOriginal > 0 && $piTotalOriginal > 0)
            ? (int) round($embeddedOriginal * min(1.0, $goodsPaidOriginal / $piTotalOriginal))
            : 0;

        $receivedOriginal = $separateOriginal + $embeddedOriginal;
        $paidOriginal = $separatePaidOriginal + $embeddedPaidOriginal;
        $outstandingOriginal = max(0, $receivedOriginal - $paidOriginal);

        $separatePres = $separateOriginal > 0 ? $fx->convertDocument($separateOriginal, $currency, $piIssue) : 0;
        $embeddedPres = $embeddedOriginal > 0 ? $fx->convertDocument($embeddedOriginal, $currency, $piIssue) : 0;
        $paidPres = $paidOriginal > 0 ? $fx->convertDocument($paidOriginal, $currency, $piIssue) : 0;
        $outstandingPres = $outstandingOriginal > 0 ? $fx->convertDocument($outstandingOriginal, $currency, $piIssue) : 0;

        $this->recordMissing($separatePres, $currency, $unconverted);
        $this->recordMissing($embeddedPres, $currency, $unconverted);
        $this->recordMissing($paidPres, $currency, $unconverted);

        $receivedPres = ($separatePres !== null && $embeddedPres !== null)
            ? $separatePres + $embeddedPres
            : null;

        return new CommissionBlock(
            receivedPresentation: $receivedPres,
            paidPresentation: $paidPres,
            outstandingPresentation: $outstandingPres,
            receivedSeparatePresentation: $separatePres,
            receivedEmbeddedPresentation: $embeddedPres,
        );
    }

    /**
     * Carrega Debit Notes do cliente (e empresas filhas) no intervalo do filtro.
     * DNs raramente são amarradas a uma PI específica — quando são, o usuário
     * em geral lança como Additional Cost no documento. Por isso a listagem
     * aqui é no nível do cliente, não por deal.
     *
     * Janela: `issued_at` se preenchido, senão `created_at`. DNs CANCELADAS
     * são ignoradas.
     *
     * @param  list<int>  $scopeIds
     * @return list<DebitNoteRow>
     */
    private function buildDebitNoteRows(array $scopeIds, DealBreakdownFilters $filters, FxConverter $fx, array &$unconverted): array
    {
        if (empty($scopeIds)) {
            return [];
        }

        $from = $filters->from->toDateString();
        $to = $filters->to->toDateString();

        $debitNotes = DebitNote::query()
            ->whereIn('company_id', $scopeIds)
            ->where('status', '!=', \App\Domain\Financial\Enums\DebitNoteStatus::CANCELLED)
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('issued_at', [$from.' 00:00:00', $to.' 23:59:59'])
                    ->orWhere(function ($q2) use ($from, $to) {
                        $q2->whereNull('issued_at')
                            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59']);
                    });
            })
            ->with(['lineItems'])
            ->orderByDesc('issued_at')
            ->orderByDesc('created_at')
            ->get();

        $rows = [];
        foreach ($debitNotes as $dn) {
            $totalOriginal = (int) $dn->total_amount;
            $paidOriginal = (int) $dn->paid_amount;
            $outstandingOriginal = max(0, $totalOriginal - $paidOriginal);

            $issueDate = $dn->issued_at
                ? CarbonImmutable::parse((string) $dn->issued_at)
                : CarbonImmutable::parse((string) $dn->created_at);

            $totalPres = $fx->convertDocument($totalOriginal, (string) $dn->currency_code, $issueDate);
            $paidPres = $fx->convertDocument($paidOriginal, (string) $dn->currency_code, $issueDate);
            $outstandingPres = ($totalPres !== null && $paidPres !== null)
                ? max(0, $totalPres - $paidPres)
                : null;

            $this->recordMissing($totalPres, (string) $dn->currency_code, $unconverted);

            $rows[] = new DebitNoteRow(
                id: $dn->id,
                reference: (string) $dn->reference,
                issuedAt: $issueDate,
                currencyOriginal: (string) $dn->currency_code,
                totalOriginal: $totalOriginal,
                totalPresentation: $totalPres,
                paidOriginal: $paidOriginal,
                paidPresentation: $paidPres,
                outstandingOriginal: $outstandingOriginal,
                outstandingPresentation: $outstandingPres,
                status: $dn->status,
                detailUrl: DebitNoteResource::getUrl('view', ['record' => $dn->id]),
            );
        }

        return $rows;
    }

    /**
     * @param  list<DealRow>  $deals
     * @param  list<DebitNoteRow>  $debitNotes
     */
    private function buildKpi(array $deals, array $debitNotes): KpiSummary
    {
        $received = 0;
        $paidSuppliers = 0;
        $paidShipments = 0;
        $margin = 0;
        $commissionReceived = 0;
        $commissionPaid = 0;

        foreach ($deals as $deal) {
            $received += (int) ($deal->receipts->paidPresentation ?? 0);
            foreach ($deal->purchaseOrders as $po) {
                $paidSuppliers += (int) ($po->paidPresentation ?? 0);
            }
            foreach ($deal->shipments as $sh) {
                $paidShipments += (int) ($sh->paidPresentation ?? 0);
                // Reembolso de frete do cliente conta como recebido.
                $received += (int) ($sh->freightReceivedPresentation ?? 0);
            }
            $margin += $deal->totals->margin;
            $commissionReceived += (int) ($deal->commission->receivedPresentation ?? 0);
            $commissionPaid += (int) ($deal->commission->paidPresentation ?? 0);
        }

        // DNs not allocable to a specific deal — subtract their total from
        // the overall KPI margin (per user convention).
        $dnTotal = 0;
        foreach ($debitNotes as $dn) {
            $dnTotal += (int) ($dn->totalPresentation ?? 0);
        }
        $margin -= $dnTotal;

        return new KpiSummary(
            totalReceived: $received,
            totalPaidSuppliers: $paidSuppliers,
            totalPaidShipments: $paidShipments,
            totalMargin: $margin,
            dealCount: count($deals),
            totalCommissionReceived: $commissionReceived,
            totalCommissionPaid: $commissionPaid,
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ProformaInvoice>  $pis
     * @return array<string, float>
     */
    /**
     * @param  list<int>  $scopeIds
     */
    private function prefetchFxCache($pis, array $scopeIds, DealBreakdownFilters $filters, string $presentationCurrency): array
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

        // Client-level Debit Notes — single batch load.
        if (! empty($scopeIds)) {
            $from = $filters->from->toDateString();
            $to = $filters->to->toDateString();
            $dns = DebitNote::query()
                ->whereIn('company_id', $scopeIds)
                ->where(function ($q) use ($from, $to) {
                    $q->whereBetween('issued_at', [$from.' 00:00:00', $to.' 23:59:59'])
                        ->orWhere(function ($q2) use ($from, $to) {
                            $q2->whereNull('issued_at')
                                ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59']);
                        });
                })
                ->select(['id', 'currency_code', 'issued_at', 'created_at'])
                ->get();
            foreach ($dns as $dn) {
                $add(
                    $dn->currency_code,
                    $dn->issued_at ? (string) $dn->issued_at : (string) $dn->created_at,
                );
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
