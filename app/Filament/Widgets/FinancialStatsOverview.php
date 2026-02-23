<?php

namespace App\Filament\Widgets;

use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Support\Money;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FinancialStatsOverview extends BaseWidget
{
    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $pendingReceivables = Payment::inbound()
            ->where('status', PaymentStatus::PENDING_APPROVAL)
            ->sum('amount');

        $approvedReceivables = Payment::inbound()
            ->approved()
            ->sum('amount');

        $pendingPayables = Payment::outbound()
            ->where('status', PaymentStatus::PENDING_APPROVAL)
            ->sum('amount');

        $approvedPayables = Payment::outbound()
            ->approved()
            ->sum('amount');

        $pendingAdditionalCosts = AdditionalCost::where('status', AdditionalCostStatus::PENDING)
            ->sum('amount_in_document_currency');

        $blockingScheduleItems = PaymentScheduleItem::where('is_blocking', true)
            ->whereNotIn('status', [
                PaymentScheduleStatus::PAID->value,
                PaymentScheduleStatus::WAIVED->value,
            ])
            ->count();

        return [
            Stat::make('Pending Receivables', Money::formatDisplay($pendingReceivables))
                ->color('warning'),
            Stat::make('Approved Receivables', Money::formatDisplay($approvedReceivables))
                ->color('success'),
            Stat::make('Pending Payables', Money::formatDisplay($pendingPayables))
                ->color('warning'),
            Stat::make('Approved Payables', Money::formatDisplay($approvedPayables))
                ->color('danger'),
            Stat::make('Pending Add. Costs', Money::formatDisplay($pendingAdditionalCosts))
                ->color('info'),
            Stat::make('Blocking Items', $blockingScheduleItems)
                ->color($blockingScheduleItems > 0 ? 'danger' : 'gray'),
        ];
    }
}
