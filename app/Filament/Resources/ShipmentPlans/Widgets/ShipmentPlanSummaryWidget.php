<?php

namespace App\Filament\Resources\ShipmentPlans\Widgets;

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
            Stat::make(__('widgets.shipment_plan.total_planned_value'), $currency.' '.Money::format($totalValue))
                ->description(__('widgets.shipment_plan.items_from_pis', ['items' => $itemCount, 'pis' => $piCount]))
                ->icon('heroicon-o-banknotes'),

            Stat::make(__('widgets.shipment_plan.total_paid'), $currency.' '.Money::format($totalPaid))
                ->description(__('widgets.shipment_plan.remaining', ['amount' => $currency.' '.Money::format($totalRemaining)]))
                ->icon('heroicon-o-check-circle')
                ->color($totalRemaining > 0 ? 'warning' : 'success'),

            Stat::make(__('widgets.shipment_plan.blocking_payments'), $blockingPending)
                ->description($blockingPending > 0
                    ? __('widgets.shipment_plan.must_be_paid_before_shipping')
                    : __('widgets.shipment_plan.all_clear'))
                ->icon('heroicon-o-exclamation-triangle')
                ->color($blockingPending > 0 ? 'danger' : 'success'),
        ];
    }
}
