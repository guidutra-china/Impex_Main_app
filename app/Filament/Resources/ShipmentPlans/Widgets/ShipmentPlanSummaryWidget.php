<?php

namespace App\Filament\Resources\ShipmentPlans\Widgets;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Infrastructure\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ShipmentPlanSummaryWidget extends StatsOverviewWidget
{
    public $record;

    protected function getStats(): array
    {
        $plan = $this->record;

        if (! $plan) {
            return [];
        }

        $totalValue = $plan->total;
        $currency = $plan->currency_code ?? 'USD';

        $scheduleItems = $plan->linkedPaymentScheduleItems;

        $totalScheduled = $scheduleItems->sum('amount');
        $totalPaid = $scheduleItems->sum('paid_amount');
        $totalRemaining = $scheduleItems->sum('remaining_amount');

        $blockingPending = $scheduleItems
            ->where('is_blocking', true)
            ->filter(fn ($item) => ! $item->status->isResolved())
            ->count();

        $itemCount = $plan->items->count();
        $piCount = $plan->items->pluck('proforma_invoice_id')->unique()->count();

        return [
            Stat::make(__('widgets.total_planned_value'), $currency . ' ' . Money::format($totalValue))
                ->description("{$itemCount} items from {$piCount} PI(s)")
                ->icon('heroicon-o-banknotes'),

            Stat::make(__('widgets.total_paid'), $currency . ' ' . Money::format($totalPaid))
                ->description($currency . ' ' . Money::format($totalRemaining) . ' remaining')
                ->icon('heroicon-o-check-circle')
                ->color($totalRemaining > 0 ? 'warning' : 'success'),

            Stat::make(__('widgets.blocking_payments'), $blockingPending)
                ->description($blockingPending > 0 ? 'Must be paid before shipping' : 'All clear')
                ->icon('heroicon-o-exclamation-triangle')
                ->color($blockingPending > 0 ? 'danger' : 'success'),
        ];
    }
}
