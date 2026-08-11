<?php

namespace App\Filament\Widgets;

use App\Domain\Financial\Actions\GeneratePaymentScheduleAction;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Inquiries\Enums\InquiryStatus;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class OperationalAlertsWidget extends Widget
{
    protected string $view = 'filament.widgets.operational-alerts';

    protected static bool $isLazy = false;

    protected static ?string $pollingInterval = '120s';

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can('view-operational-dashboard') ?? false;
    }

    protected function getViewData(): array
    {
        $alerts = [];

        // Overdue — A/P (PurchaseOrder-owned schedule items)
        $overduePayables = PaymentScheduleItem::query()
            ->where('status', PaymentScheduleStatus::OVERDUE)
            ->where('is_credit', false)
            ->where('payable_type', PurchaseOrder::class)
            ->count();

        if ($overduePayables > 0) {
            $alerts[] = [
                'type' => 'danger',
                'icon' => 'heroicon-o-exclamation-circle',
                'title' => $overduePayables.' overdue payable'.($overduePayables > 1 ? 's' : ''),
                'description' => __('widgets.alerts.overdue_payables_desc'),
                'url' => route('filament.admin.resources.accounts-payable.index'),
                'action' => __('widgets.alerts.view_payables'),
            ];
        }

        // Overdue — A/R (ProformaInvoice-owned schedule items)
        $overdueReceivables = PaymentScheduleItem::query()
            ->where('status', PaymentScheduleStatus::OVERDUE)
            ->where('is_credit', false)
            ->where('payable_type', ProformaInvoice::class)
            ->count();

        if ($overdueReceivables > 0) {
            $alerts[] = [
                'type' => 'danger',
                'icon' => 'heroicon-o-exclamation-circle',
                'title' => $overdueReceivables.' overdue receivable'.($overdueReceivables > 1 ? 's' : ''),
                'description' => __('widgets.alerts.overdue_receivables_desc'),
                'url' => route('filament.admin.resources.accounts-receivable.index'),
                'action' => __('widgets.alerts.view_receivables'),
            ];
        }

        // Pending approval — A/P
        $pendingApprovalPayables = Payment::query()
            ->where('status', PaymentStatus::PENDING_APPROVAL)
            ->where('direction', PaymentDirection::OUTBOUND)
            ->count();

        if ($pendingApprovalPayables > 0) {
            $alerts[] = [
                'type' => 'warning',
                'icon' => 'heroicon-o-clock',
                'title' => $pendingApprovalPayables.' payable'.($pendingApprovalPayables > 1 ? 's' : '').' awaiting approval',
                'description' => __('widgets.alerts.pending_approval_payables_desc'),
                'url' => route('filament.admin.resources.accounts-payable.index', [
                    'filters' => ['status' => ['value' => PaymentStatus::PENDING_APPROVAL->value]],
                ]),
                'action' => __('widgets.alerts.review_payables'),
            ];
        }

        // Pending approval — A/R
        $pendingApprovalReceivables = Payment::query()
            ->where('status', PaymentStatus::PENDING_APPROVAL)
            ->where('direction', PaymentDirection::INBOUND)
            ->count();

        if ($pendingApprovalReceivables > 0) {
            $alerts[] = [
                'type' => 'warning',
                'icon' => 'heroicon-o-clock',
                'title' => $pendingApprovalReceivables.' receivable'.($pendingApprovalReceivables > 1 ? 's' : '').' awaiting approval',
                'description' => __('widgets.alerts.pending_approval_receivables_desc'),
                'url' => route('filament.admin.resources.accounts-receivable.index', [
                    'filters' => ['status' => ['value' => PaymentStatus::PENDING_APPROVAL->value]],
                ]),
                'action' => __('widgets.alerts.review_receivables'),
            ];
        }

        $finalizedPIsWithoutPO = ProformaInvoice::query()
            ->where('status', ProformaInvoiceStatus::FINALIZED)
            ->whereDoesntHave('purchaseOrders')
            ->count();

        if ($finalizedPIsWithoutPO > 0) {
            $alerts[] = [
                'type' => 'info',
                'icon' => 'heroicon-o-document-text',
                'title' => $finalizedPIsWithoutPO.' finalized PI'.($finalizedPIsWithoutPO > 1 ? 's' : '').' without PO',
                'description' => __('widgets.alerts.finalized_pi_desc'),
                'url' => route('filament.admin.resources.proforma-invoices.index', [
                    'filters' => [
                        'status' => ['value' => ProformaInvoiceStatus::FINALIZED->value],
                        'without_po' => ['isActive' => true],
                    ],
                ]),
                'action' => __('widgets.alerts.view_pis'),
            ];
        }

        $stalledPOs = PurchaseOrder::query()
            ->whereIn('status', [
                PurchaseOrderStatus::CONFIRMED,
                PurchaseOrderStatus::IN_PRODUCTION,
                PurchaseOrderStatus::AWAITING_SHIPMENT,
            ])
            ->where('updated_at', '<', now()->subDays(15))
            ->count();

        if ($stalledPOs > 0) {
            $alerts[] = [
                'type' => 'warning',
                'icon' => 'heroicon-o-pause-circle',
                'title' => $stalledPOs.' PO'.($stalledPOs > 1 ? 's' : '').' with no updates (15+ days)',
                'description' => __('widgets.alerts.stalled_po_desc'),
                'url' => route('filament.admin.resources.purchase-orders.index', [
                    'filters' => ['stalled' => ['isActive' => true]],
                ]),
                'action' => __('widgets.alerts.view_pos'),
            ];
        }

        $openInquiries = Inquiry::query()
            ->whereIn('status', [InquiryStatus::RECEIVED, InquiryStatus::QUOTING])
            ->where('created_at', '<', now()->subDays(7))
            ->count();

        if ($openInquiries > 0) {
            $alerts[] = [
                'type' => 'info',
                'icon' => 'heroicon-o-magnifying-glass',
                'title' => $openInquiries.' inquir'.($openInquiries > 1 ? 'ies' : 'y').' open for 7+ days',
                'description' => __('widgets.alerts.open_inquiries_desc'),
                'url' => route('filament.admin.resources.inquiries.index', [
                    'filters' => ['open_aging' => ['isActive' => true]],
                ]),
                'action' => __('widgets.alerts.view_inquiries'),
            ];
        }

        // Due this week — A/P
        $duePayablesThisWeek = PaymentScheduleItem::query()
            ->where('status', PaymentScheduleStatus::DUE)
            ->where('is_credit', false)
            ->where('payable_type', PurchaseOrder::class)
            ->whereBetween('due_date', [now()->startOfDay(), now()->addDays(7)->endOfDay()])
            ->count();

        if ($duePayablesThisWeek > 0) {
            $alerts[] = [
                'type' => 'primary',
                'icon' => 'heroicon-o-calendar',
                'title' => $duePayablesThisWeek.' payable'.($duePayablesThisWeek > 1 ? 's' : '').' due this week',
                'description' => __('widgets.alerts.due_this_week_payables_desc'),
                'url' => route('filament.admin.resources.accounts-payable.index'),
                'action' => __('widgets.alerts.view_payables'),
            ];
        }

        // Due this week — A/R
        $dueReceivablesThisWeek = PaymentScheduleItem::query()
            ->where('status', PaymentScheduleStatus::DUE)
            ->where('is_credit', false)
            ->where('payable_type', ProformaInvoice::class)
            ->whereBetween('due_date', [now()->startOfDay(), now()->addDays(7)->endOfDay()])
            ->count();

        if ($dueReceivablesThisWeek > 0) {
            $alerts[] = [
                'type' => 'primary',
                'icon' => 'heroicon-o-calendar',
                'title' => $dueReceivablesThisWeek.' receivable'.($dueReceivablesThisWeek > 1 ? 's' : '').' due this week',
                'description' => __('widgets.alerts.due_this_week_receivables_desc'),
                'url' => route('filament.admin.resources.accounts-receivable.index'),
                'action' => __('widgets.alerts.view_receivables'),
            ];
        }

        // ETA changed by forwarder — needs payment schedule recalc.
        // Scoped to the responsible user so each admin only sees their own shipments.
        $pendingEtaShipments = Shipment::query()
            ->where('eta_change_pending_recalc', true)
            ->where('responsible_user_id', auth()->id())
            ->orderBy('eta_changed_at', 'desc')
            ->limit(10)
            ->get();

        foreach ($pendingEtaShipments as $shipment) {
            $alerts[] = [
                'type' => 'warning',
                'icon' => 'heroicon-o-arrow-path',
                'title' => __('widgets.alerts.eta_changed_title', [
                    'ref' => $shipment->reference,
                    'eta' => optional($shipment->eta)->format('d/m/Y') ?? '—',
                ]),
                'description' => __('widgets.alerts.eta_changed_desc', [
                    'forwarder' => $shipment->forwarderCompany?->name ?? '—',
                ]),
                'url' => route('filament.admin.resources.shipments.view', ['record' => $shipment->getKey()]),
                'action' => __('widgets.alerts.view_shipment'),
                'recalc_shipment_id' => $shipment->getKey(),
                'recalc_action_label' => __('widgets.alerts.recalc_payment_schedule'),
            ];
        }

        // Payment schedules that no longer match current document values
        // (values edited without clicking "Regenerate").
        foreach ($this->getStaleScheduleFindings() as $finding) {
            $alerts[] = [
                'type' => 'danger',
                'icon' => 'heroicon-o-arrow-path',
                'title' => __('widgets.alerts.stale_schedule_title', ['ref' => $finding['reference']]),
                'description' => __('widgets.alerts.stale_schedule_desc', [
                    'scheduled' => Money::format($finding['actual']),
                    'expected' => Money::format($finding['expected']),
                ]),
                'url' => $finding['url'],
                'action' => __('widgets.alerts.view_document'),
                'regen_payable_type' => $finding['type'],
                'regen_payable_id' => $finding['id'],
                'regen_action_label' => __('widgets.alerts.regenerate_schedule'),
            ];
        }

        return ['alerts' => $alerts];
    }

    /**
     * Cached scan (5 min): every active PI/PO/Shipment whose base payment
     * schedule diverges from the live document values. The scan runs
     * isScheduleStale() per document, so it must not run on every poll.
     *
     * @return array<int, array{type: string, id: int, reference: string, actual: int, expected: int, url: string}>
     */
    protected function getStaleScheduleFindings(): array
    {
        return Cache::remember('operational-alerts:stale-schedules', 300, function () {
            $findings = [];

            $sources = [
                ['pi', ProformaInvoice::query()->active(), 'filament.admin.resources.proforma-invoices.view'],
                ['po', PurchaseOrder::query()->active(), 'filament.admin.resources.purchase-orders.view'],
                ['shipment', Shipment::query()->whereNot('status', ShipmentStatus::CANCELLED), 'filament.admin.resources.shipments.view'],
            ];

            foreach ($sources as [$type, $query, $route]) {
                $query
                    ->whereHas('paymentScheduleItems', fn ($q) => $q->whereNotNull('payment_term_stage_id'))
                    ->chunkById(100, function ($documents) use (&$findings, $type, $route) {
                        foreach ($documents as $document) {
                            $staleness = $document->scheduleStaleness();

                            if ($staleness['state'] !== 'stale') {
                                continue;
                            }

                            $findings[] = [
                                'type' => $type,
                                'id' => $document->getKey(),
                                'reference' => $document->reference,
                                'actual' => $staleness['actual'],
                                'expected' => $staleness['expected'],
                                'url' => route($route, ['record' => $document->getKey()]),
                            ];
                        }
                    });
            }

            return $findings;
        });
    }

    public function regenerateStaleSchedule(string $type, int $id): void
    {
        if (! auth()->user()?->can('generate-payment-schedule')) {
            Notification::make()
                ->danger()
                ->title(__('widgets.alerts.regenerate_unauthorized'))
                ->send();

            return;
        }

        $document = match ($type) {
            'pi' => ProformaInvoice::find($id),
            'po' => PurchaseOrder::find($id),
            'shipment' => Shipment::find($id),
            default => null,
        };

        if (! $document) {
            return;
        }

        $count = $document instanceof Shipment
            ? app(GeneratePaymentScheduleAction::class)->regenerateForShipment($document)
            : app(GeneratePaymentScheduleAction::class)->regenerate($document);

        Cache::forget('operational-alerts:stale-schedules');

        Notification::make()
            ->success()
            ->title(__('widgets.alerts.regenerate_done_title'))
            ->body(__('widgets.alerts.regenerate_done_body', ['ref' => $document->reference, 'count' => $count]))
            ->send();
    }

    public function recalcShipment(int $shipmentId): void
    {
        $shipment = Shipment::find($shipmentId);
        if (! $shipment) {
            return;
        }

        // Authorisation: only the responsible admin can clear and recalc.
        if ($shipment->responsible_user_id !== auth()->id()) {
            Notification::make()
                ->danger()
                ->title(__('widgets.alerts.recalc_unauthorized'))
                ->send();

            return;
        }

        $count = DB::transaction(function () use ($shipment) {
            $count = app(GeneratePaymentScheduleAction::class)->regenerateForShipment($shipment);

            // Clear the admin-side pending flag only. Keep eta_changed_at/by
            // as history so the client portal widget can still surface the
            // change for the next 14 days.
            $shipment->forceFill([
                'eta_change_pending_recalc' => false,
            ])->save();

            return $count;
        });

        Cache::forget('operational-alerts:stale-schedules');

        Notification::make()
            ->success()
            ->title(__('widgets.alerts.recalc_done_title'))
            ->body(__('widgets.alerts.recalc_done_body', ['ref' => $shipment->reference, 'count' => $count]))
            ->send();
    }
}
