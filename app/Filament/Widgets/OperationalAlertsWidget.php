<?php

namespace App\Filament\Widgets;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Inquiries\Enums\InquiryStatus;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use Filament\Widgets\Widget;

class OperationalAlertsWidget extends Widget
{
    protected string $view = 'filament.widgets.operational-alerts';

    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can('view-operational-dashboard') ?? false;
    }

    protected function getViewData(): array
    {
        $alerts = [];

        $overduePayments = PaymentScheduleItem::query()
            ->where('status', PaymentScheduleStatus::OVERDUE)
            ->where('is_credit', false)
            ->count();

        if ($overduePayments > 0) {
            $alerts[] = [
                'type' => 'danger',
                'icon' => 'heroicon-o-exclamation-circle',
                'title' => $overduePayments . ' overdue payment' . ($overduePayments > 1 ? 's' : ''),
                'description' => 'Schedule items past due date without full payment',
                'url' => route('filament.admin.resources.payments.index'),
                'action' => 'View Payments',
            ];
        }

        $pendingApproval = Payment::query()
            ->where('status', PaymentStatus::PENDING_APPROVAL)
            ->count();

        if ($pendingApproval > 0) {
            $alerts[] = [
                'type' => 'warning',
                'icon' => 'heroicon-o-clock',
                'title' => $pendingApproval . ' payment' . ($pendingApproval > 1 ? 's' : '') . ' awaiting approval',
                'description' => 'Payments submitted and waiting for manager review',
                'url' => route('filament.admin.resources.payments.index'),
                'action' => 'Review Payments',
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
                'title' => $finalizedPIsWithoutPO . ' finalized PI' . ($finalizedPIsWithoutPO > 1 ? 's' : '') . ' without PO',
                'description' => 'Proforma invoices confirmed but no purchase order created yet',
                'url' => route('filament.admin.resources.proforma-invoices.index'),
                'action' => 'View PIs',
            ];
        }

        $stalledPOs = PurchaseOrder::query()
            ->whereIn('status', [
                PurchaseOrderStatus::CONFIRMED,
                PurchaseOrderStatus::IN_PRODUCTION,
            ])
            ->where('updated_at', '<', now()->subDays(15))
            ->count();

        if ($stalledPOs > 0) {
            $alerts[] = [
                'type' => 'warning',
                'icon' => 'heroicon-o-pause-circle',
                'title' => $stalledPOs . ' PO' . ($stalledPOs > 1 ? 's' : '') . ' with no updates (15+ days)',
                'description' => 'Purchase orders in production with no activity for over 15 days',
                'url' => route('filament.admin.resources.purchase-orders.index'),
                'action' => 'View POs',
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
                'title' => $openInquiries . ' inquir' . ($openInquiries > 1 ? 'ies' : 'y') . ' open for 7+ days',
                'description' => 'Client inquiries that may need follow-up',
                'url' => route('filament.admin.resources.inquiries.index'),
                'action' => 'View Inquiries',
            ];
        }

        $dueThisWeek = PaymentScheduleItem::query()
            ->where('status', PaymentScheduleStatus::DUE)
            ->where('is_credit', false)
            ->whereBetween('due_date', [now()->startOfDay(), now()->addDays(7)->endOfDay()])
            ->count();

        if ($dueThisWeek > 0) {
            $alerts[] = [
                'type' => 'primary',
                'icon' => 'heroicon-o-calendar',
                'title' => $dueThisWeek . ' payment' . ($dueThisWeek > 1 ? 's' : '') . ' due this week',
                'description' => 'Schedule items due within the next 7 days',
                'url' => route('filament.admin.resources.payments.index'),
                'action' => 'View Schedule',
            ];
        }

        return ['alerts' => $alerts];
    }
}
