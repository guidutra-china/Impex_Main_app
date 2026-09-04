<?php

namespace App\Domain\CRM\Services;

use App\Domain\CRM\DataTransferObjects\Client360FinancialSummary;
use App\Domain\CRM\DataTransferObjects\Client360KpiSummary;
use App\Domain\CRM\DataTransferObjects\Client360TimelineEvent;
use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Inquiries\Enums\InquiryStatus;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use Illuminate\Support\Collection;

/**
 * Aggregates all data needed by the Client 360 page for a single client.
 *
 * Branch consolidation: when the given client is a matrix (parent_company_id IS NULL),
 * all queries automatically include data attached to its branches as well.
 *
 * No cross-currency conversion is performed. All monetary aggregates are
 * grouped by currency_code so the UI can render them side by side.
 *
 * This service is read-only and intentionally returns plain Eloquent collections
 * (or DTOs for aggregates) — no pagination, no caching. v1 is real-time.
 */
class Client360DataService
{
    /** @var array<int>|null Lazy-loaded list of company IDs (matrix + branches). */
    private ?array $companyIdsCache = null;

    public function __construct(private readonly Company $client) {}

    /**
     * IDs of every company that should be considered "this client":
     * the client itself plus all of its branches when it is a matrix.
     *
     * @return array<int>
     */
    public function companyIds(): array
    {
        if ($this->companyIdsCache !== null) {
            return $this->companyIdsCache;
        }

        $ids = [$this->client->id];

        if ($this->client->parent_company_id === null) {
            $ids = array_merge(
                $ids,
                $this->client->branches()->pluck('id')->all(),
            );
        }

        return $this->companyIdsCache = array_values(array_unique($ids));
    }

    // === KPIs ===

    public function kpis(): Client360KpiSummary
    {
        $companyIds = $this->companyIds();

        $activeInquiries = Inquiry::query()
            ->whereIn('company_id', $companyIds)
            ->whereNotIn('status', [
                InquiryStatus::WON,
                InquiryStatus::LOST,
                InquiryStatus::CANCELLED,
            ])
            ->count();

        $openInvoices = ProformaInvoice::query()
            ->whereIn('company_id', $companyIds)
            ->whereNotIn('status', [
                ProformaInvoiceStatus::CANCELLED,
                ProformaInvoiceStatus::FINALIZED,
            ])
            ->with('items')
            ->get();

        $openInvoicesValueByCurrency = [];
        foreach ($openInvoices as $invoice) {
            $currency = $invoice->currency_code ?: 'USD';
            $openInvoicesValueByCurrency[$currency] = ($openInvoicesValueByCurrency[$currency] ?? 0)
                + (int) $invoice->grand_total;
        }

        $posInProgress = PurchaseOrder::query()
            ->whereIn('proforma_invoice_id', $this->clientProformaInvoiceIds())
            ->whereIn('status', [
                PurchaseOrderStatus::SENT,
                PurchaseOrderStatus::CONFIRMED,
                PurchaseOrderStatus::IN_PRODUCTION,
                PurchaseOrderStatus::AWAITING_SHIPMENT,
                PurchaseOrderStatus::SHIPPED,
            ])
            ->count();

        $shipmentsInTransit = Shipment::query()
            ->where(function ($q) use ($companyIds) {
                $q->whereIn('company_id', $companyIds)
                    ->orWhereIn('company_branch_id', $companyIds);
            })
            ->whereIn('status', [
                ShipmentStatus::BOOKED,
                ShipmentStatus::CUSTOMS,
                ShipmentStatus::IN_TRANSIT,
            ])
            ->count();

        $financialSummary = $this->financialSummary();

        $alertsCount = array_sum($financialSummary->overdueReceivablesByCurrency) > 0
            ? count(array_filter($financialSummary->overdueReceivablesByCurrency))
            : 0;
        // Add overdue payables to the alert count too — they affect the client's flow.
        $alertsCount += count(array_filter($financialSummary->overduePayablesByCurrency));

        return new Client360KpiSummary(
            activeInquiriesCount: $activeInquiries,
            openInvoicesCount: $openInvoices->count(),
            openInvoicesValueByCurrency: $openInvoicesValueByCurrency,
            purchaseOrdersInProgressCount: $posInProgress,
            shipmentsInTransitCount: $shipmentsInTransit,
            pendingReceivablesByCurrency: $financialSummary->receivablesByCurrency,
            alertsCount: $alertsCount,
        );
    }

    // === Module sections ===

    public function inquiries(): Collection
    {
        return Inquiry::query()
            ->whereIn('company_id', $this->companyIds())
            ->with(['company', 'items'])
            ->orderByDesc('received_at')
            ->orderByDesc('created_at')
            ->get();
    }

    public function proformaInvoices(): Collection
    {
        return ProformaInvoice::query()
            ->whereIn('company_id', $this->companyIds())
            ->with(['company', 'items', 'paymentScheduleItems'])
            ->orderByDesc('issue_date')
            ->orderByDesc('created_at')
            ->get();
    }

    public function purchaseOrders(): Collection
    {
        $piIds = $this->clientProformaInvoiceIds();

        if ($piIds === []) {
            return collect();
        }

        return PurchaseOrder::query()
            ->whereIn('proforma_invoice_id', $piIds)
            ->with([
                'supplierCompany',
                'proformaInvoice.items',
                'proformaInvoice.additionalCosts',
                'items',
                'paymentScheduleItems',
            ])
            ->orderByDesc('issue_date')
            ->orderByDesc('created_at')
            ->get();
    }

    public function shipments(): Collection
    {
        $companyIds = $this->companyIds();

        return Shipment::query()
            ->where(function ($q) use ($companyIds) {
                $q->whereIn('company_id', $companyIds)
                    ->orWhereIn('company_branch_id', $companyIds);
            })
            ->with([
                'company',
                'companyBranch',
                'forwarderCompany',
                // Freight costs only — used by the view to show shipping cost per row.
                'additionalCosts' => fn ($q) => $q->where(
                    'cost_type',
                    \App\Domain\Financial\Enums\AdditionalCostType::FREIGHT,
                ),
            ])
            ->orderByDesc('etd')
            ->orderByDesc('created_at')
            ->get();
    }

    public function recentPayments(int $limit = 20): Collection
    {
        $companyIds = $this->companyIds();
        $piIds = $this->clientProformaInvoiceIds();
        $poIds = $this->clientPurchaseOrderIds();

        return Payment::query()
            ->where(function ($q) use ($companyIds, $piIds, $poIds) {
                // Inbound payments where the counterparty is the client itself.
                $q->where(function ($inner) use ($companyIds) {
                    $inner->where('direction', PaymentDirection::INBOUND)
                        ->whereIn('company_id', $companyIds);
                });
                // Outbound payments allocated against this client's POs/PIs.
                if ($piIds !== [] || $poIds !== []) {
                    $q->orWhereHas(
                        'allocations.scheduleItem',
                        fn ($inner) => $inner->where(function ($w) use ($piIds, $poIds) {
                            if ($piIds !== []) {
                                $w->orWhere(function ($pi) use ($piIds) {
                                    $pi->where('payable_type', ProformaInvoice::class)
                                        ->whereIn('payable_id', $piIds);
                                });
                            }
                            if ($poIds !== []) {
                                $w->orWhere(function ($po) use ($poIds) {
                                    $po->where('payable_type', PurchaseOrder::class)
                                        ->whereIn('payable_id', $poIds);
                                });
                            }
                        }),
                    );
                }
            })
            ->with(['company', 'allocations.scheduleItem'])
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    // === Financial summary ===

    public function financialSummary(): Client360FinancialSummary
    {
        $piIds = $this->clientProformaInvoiceIds();
        $poIds = $this->clientPurchaseOrderIds();

        $unresolved = [
            PaymentScheduleStatus::PENDING->value,
            PaymentScheduleStatus::DUE->value,
            PaymentScheduleStatus::OVERDUE->value,
        ];

        $receivables = $piIds === []
            ? collect()
            : PaymentScheduleItem::query()
                ->where('payable_type', ProformaInvoice::class)
                ->whereIn('payable_id', $piIds)
                ->where('is_credit', false)
                ->whereIn('status', $unresolved)
                ->get(['id', 'currency_code', 'amount', 'status']);

        $payables = $poIds === []
            ? collect()
            : PaymentScheduleItem::query()
                ->where('payable_type', PurchaseOrder::class)
                ->whereIn('payable_id', $poIds)
                ->where('is_credit', false)
                ->whereIn('status', $unresolved)
                ->get(['id', 'currency_code', 'amount', 'status']);

        return new Client360FinancialSummary(
            receivablesByCurrency: $this->sumByCurrency($receivables),
            receivablesCountByCurrency: $this->countByCurrency($receivables),
            overdueReceivablesByCurrency: $this->sumByCurrency(
                $receivables->where('status', PaymentScheduleStatus::OVERDUE),
            ),
            payablesByCurrency: $this->sumByCurrency($payables),
            payablesCountByCurrency: $this->countByCurrency($payables),
            overduePayablesByCurrency: $this->sumByCurrency(
                $payables->where('status', PaymentScheduleStatus::OVERDUE),
            ),
        );
    }

    // === Timeline ===

    /**
     * Anchor-based timeline merging events from all client modules,
     * sorted from most recent to oldest.
     *
     * @return Collection<int, Client360TimelineEvent>
     */
    public function timeline(int $limit = 30): Collection
    {
        $events = collect();

        foreach ($this->inquiries() as $inquiry) {
            $events->push(new Client360TimelineEvent(
                occurredAt: $inquiry->received_at ?? $inquiry->created_at,
                type: 'inquiry_created',
                title: __('client_360.timeline.inquiry_created', ['ref' => $inquiry->reference]),
                subtitle: $inquiry->status->getLabel(),
                url: null,
            ));
        }

        foreach ($this->proformaInvoices() as $invoice) {
            $events->push(new Client360TimelineEvent(
                occurredAt: $invoice->issue_date ?? $invoice->created_at,
                type: 'invoice_issued',
                title: __('client_360.timeline.invoice_issued', ['ref' => $invoice->reference]),
                subtitle: $invoice->currency_code.' '.number_format($invoice->grand_total / 10000, 2),
                url: null,
            ));

            if ($invoice->confirmed_at) {
                $events->push(new Client360TimelineEvent(
                    occurredAt: $invoice->confirmed_at,
                    type: 'invoice_confirmed',
                    title: __('client_360.timeline.invoice_confirmed', ['ref' => $invoice->reference]),
                    subtitle: null,
                    url: null,
                ));
            }
        }

        foreach ($this->purchaseOrders() as $po) {
            $events->push(new Client360TimelineEvent(
                occurredAt: $po->issue_date ?? $po->created_at,
                type: 'po_issued',
                title: __('client_360.timeline.po_issued', ['ref' => $po->reference]),
                subtitle: $po->supplierCompany?->name,
                url: null,
            ));
        }

        foreach ($this->shipments() as $shipment) {
            if ($shipment->actual_departure) {
                $events->push(new Client360TimelineEvent(
                    occurredAt: $shipment->actual_departure,
                    type: 'shipment_departed',
                    title: __('client_360.timeline.shipment_departed', ['ref' => $shipment->reference]),
                    subtitle: trim(($shipment->origin_port ?? '').' → '.($shipment->destination_port ?? ''), ' →'),
                    url: null,
                ));
            }
            if ($shipment->actual_arrival) {
                $events->push(new Client360TimelineEvent(
                    occurredAt: $shipment->actual_arrival,
                    type: 'shipment_arrived',
                    title: __('client_360.timeline.shipment_arrived', ['ref' => $shipment->reference]),
                    subtitle: $shipment->destination_port,
                    url: null,
                ));
            }
        }

        foreach ($this->recentPayments($limit) as $payment) {
            $events->push(new Client360TimelineEvent(
                occurredAt: $payment->payment_date ?? $payment->created_at,
                type: $payment->direction === PaymentDirection::INBOUND ? 'payment_received' : 'payment_sent',
                title: __(
                    $payment->direction === PaymentDirection::INBOUND
                        ? 'client_360.timeline.payment_received'
                        : 'client_360.timeline.payment_sent',
                    ['amount' => $payment->currency_code.' '.number_format($payment->amount / 10000, 2)],
                ),
                subtitle: trim(($payment->number ?? '').' '.($payment->reference ?? '')) ?: null,
                url: null,
            ));
        }

        return $events
            ->filter(fn (Client360TimelineEvent $e) => $e->occurredAt !== null)
            ->sortByDesc(fn (Client360TimelineEvent $e) => $e->occurredAt->getTimestamp())
            ->take($limit)
            ->values();
    }

    // === Role-aware helpers (the company may also be a supplier and/or a forwarder) ===

    public function isClient(): bool
    {
        return $this->client->isClient();
    }

    public function isSupplier(): bool
    {
        return $this->client->isSupplier();
    }

    public function isForwarder(): bool
    {
        return $this->client->isForwarder();
    }

    /** @return Collection<int, CompanyRole> */
    public function roles(): Collection
    {
        return $this->client->companyRoles->pluck('role');
    }

    // === Supplier-side queries (used when the company is a supplier) ===

    /**
     * Purchase orders this company received as a supplier.
     * Branch consolidation is intentionally NOT applied here — supplier
     * branches are rare and PO supplier_company_id always points at one
     * specific entity.
     */
    public function supplierPurchaseOrders(): Collection
    {
        return PurchaseOrder::query()
            ->whereIn('supplier_company_id', $this->companyIds())
            ->with(['proformaInvoice.company', 'items', 'paymentScheduleItems'])
            ->orderByDesc('issue_date')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Outbound payments sent to this company (when it acts as supplier or forwarder).
     */
    public function outboundPayments(int $limit = 30): Collection
    {
        return Payment::query()
            ->where('direction', PaymentDirection::OUTBOUND)
            ->whereIn('company_id', $this->companyIds())
            ->with(['allocations.scheduleItem'])
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Pending payment schedule items grouped by currency for POs sent to
     * this supplier — i.e. what we still owe them.
     *
     * @return array{by_currency: array<string, int>, count_by_currency: array<string, int>, overdue_by_currency: array<string, int>}
     */
    public function supplierPayables(): array
    {
        $poIds = PurchaseOrder::query()
            ->whereIn('supplier_company_id', $this->companyIds())
            ->pluck('id')
            ->all();

        if ($poIds === []) {
            return ['by_currency' => [], 'count_by_currency' => [], 'overdue_by_currency' => []];
        }

        $unresolved = [
            PaymentScheduleStatus::PENDING->value,
            PaymentScheduleStatus::DUE->value,
            PaymentScheduleStatus::OVERDUE->value,
        ];

        $items = PaymentScheduleItem::query()
            ->where('payable_type', PurchaseOrder::class)
            ->whereIn('payable_id', $poIds)
            ->where('is_credit', false)
            ->whereIn('status', $unresolved)
            ->get(['id', 'currency_code', 'amount', 'status']);

        return [
            'by_currency' => $this->sumByCurrency($items),
            'count_by_currency' => $this->countByCurrency($items),
            'overdue_by_currency' => $this->sumByCurrency(
                $items->where('status', PaymentScheduleStatus::OVERDUE),
            ),
        ];
    }

    // === Forwarder-side queries (used when the company is a forwarder) ===

    /**
     * Shipments this company is handling as the forwarder.
     */
    public function forwarderShipments(): Collection
    {
        return Shipment::query()
            ->whereIn('forwarder_company_id', $this->companyIds())
            ->with(['company', 'companyBranch'])
            ->orderByDesc('etd')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Freight additional costs billed by this forwarder.
     * Used to show "what was charged to us by this forwarder".
     */
    public function forwarderFreightCosts(): Collection
    {
        return AdditionalCost::query()
            ->whereIn('forwarder_company_id', $this->companyIds())
            ->where('cost_type', AdditionalCostType::FREIGHT)
            ->with(['costable'])
            ->orderByDesc('cost_date')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Sum forwarder freight costs by currency.
     *
     * @return array<string, int>
     */
    public function forwarderFreightTotalsByCurrency(): array
    {
        $result = [];
        foreach ($this->forwarderFreightCosts() as $cost) {
            $currency = $cost->currency_code ?: 'USD';
            $result[$currency] = ($result[$currency] ?? 0) + (int) $cost->amount;
        }

        return $result;
    }

    // === Internal helpers ===

    /** @return array<int> */
    private function clientProformaInvoiceIds(): array
    {
        return ProformaInvoice::query()
            ->whereIn('company_id', $this->companyIds())
            ->pluck('id')
            ->all();
    }

    /** @return array<int> */
    private function clientPurchaseOrderIds(): array
    {
        $piIds = $this->clientProformaInvoiceIds();
        if ($piIds === []) {
            return [];
        }

        return PurchaseOrder::query()
            ->whereIn('proforma_invoice_id', $piIds)
            ->pluck('id')
            ->all();
    }

    /**
     * @param  Collection<int, PaymentScheduleItem>  $items
     * @return array<string, int>
     */
    private function sumByCurrency(Collection $items): array
    {
        return $items
            ->groupBy('currency_code')
            ->map(fn (Collection $group) => (int) $group->sum('amount'))
            ->all();
    }

    /**
     * @param  Collection<int, PaymentScheduleItem>  $items
     * @return array<string, int>
     */
    private function countByCurrency(Collection $items): array
    {
        return $items
            ->groupBy('currency_code')
            ->map(fn (Collection $group) => $group->count())
            ->all();
    }
}
