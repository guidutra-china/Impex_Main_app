<?php

namespace App\Filament\Widgets;

use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FinancialStatsOverview extends BaseWidget
{
    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $outstandingReceivables = PaymentScheduleItem::query()
            ->where('payable_type', (new ProformaInvoice)->getMorphClass())
            ->whereNotIn('status', [
                PaymentScheduleStatus::PAID->value,
                PaymentScheduleStatus::WAIVED->value,
            ])
            ->sum('amount');

        $receivedTotal = Payment::inbound()
            ->approved()
            ->sum('amount');

        $outstandingPayables = PaymentScheduleItem::query()
            ->where('payable_type', (new PurchaseOrder)->getMorphClass())
            ->whereNotIn('status', [
                PaymentScheduleStatus::PAID->value,
                PaymentScheduleStatus::WAIVED->value,
            ])
            ->sum('amount');

        $paidTotal = Payment::outbound()
            ->approved()
            ->sum('amount');

        $pendingApproval = Payment::query()
            ->where('status', PaymentStatus::PENDING_APPROVAL)
            ->count();

        $openPIs = ProformaInvoice::whereHas('paymentScheduleItems', function ($q) {
                $q->whereNotIn('status', [
                    PaymentScheduleStatus::PAID->value,
                    PaymentScheduleStatus::WAIVED->value,
                ]);
            })
            ->count();

        return [
            Stat::make('Outstanding Receivables', Money::formatDisplay($outstandingReceivables))
                ->description('From clients (unpaid schedule)')
                ->color($outstandingReceivables > 0 ? 'warning' : 'gray'),
            Stat::make('Received', Money::formatDisplay($receivedTotal))
                ->description('Approved inbound payments')
                ->color('success'),
            Stat::make('Outstanding Payables', Money::formatDisplay($outstandingPayables))
                ->description('To suppliers (unpaid schedule)')
                ->color($outstandingPayables > 0 ? 'warning' : 'gray'),
            Stat::make('Paid', Money::formatDisplay($paidTotal))
                ->description('Approved outbound payments')
                ->color('danger'),
            Stat::make('Pending Approval', $pendingApproval)
                ->description('Payments awaiting approval')
                ->color($pendingApproval > 0 ? 'warning' : 'gray'),
            Stat::make('Open PIs', $openPIs)
                ->description('PIs with pending payments')
                ->color($openPIs > 0 ? 'info' : 'gray'),
        ];
    }
}
