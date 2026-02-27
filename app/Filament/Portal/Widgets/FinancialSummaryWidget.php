<?php

namespace App\Filament\Portal\Widgets;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FinancialSummaryWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        return auth()->user()?->can('portal:view-financial-summary') ?? false;
    }

    protected function getStats(): array
    {
        $tenant = Filament::getTenant();
        if (! $tenant) {
            return [];
        }

        $companyId = $tenant->getKey();

        $totalPiValue = ProformaInvoice::where('company_id', $companyId)
            ->whereNotIn('status', ['cancelled', 'draft'])
            ->get()
            ->sum(fn ($pi) => $pi->total);

        $scheduleItems = PaymentScheduleItem::whereHasMorph('payable', [ProformaInvoice::class], function ($query) use ($companyId) {
            $query->where('company_id', $companyId);
        })->get();

        $totalPaid = $scheduleItems
            ->where('status', PaymentScheduleStatus::PAID)
            ->sum('amount');

        $totalPending = $scheduleItems
            ->whereIn('status', [PaymentScheduleStatus::PENDING, PaymentScheduleStatus::OVERDUE])
            ->sum('amount');

        return [
            Stat::make(__('widgets.portal.total_pi_value'), 'USD ' . Money::format($totalPiValue))
                ->description(__('widgets.portal.confirmed_proforma_invoices'))
                ->icon('heroicon-o-document-check')
                ->color('primary'),
            Stat::make(__('widgets.portal.total_paid'), 'USD ' . Money::format($totalPaid))
                ->description(__('widgets.portal.payments_received'))
                ->icon('heroicon-o-check-circle')
                ->color('success'),
            Stat::make(__('widgets.portal.pending_balance'), 'USD ' . Money::format($totalPending))
                ->description(__('widgets.portal.outstanding_payments'))
                ->icon('heroicon-o-clock')
                ->color($totalPending > 0 ? 'warning' : 'success'),
        ];
    }
}
