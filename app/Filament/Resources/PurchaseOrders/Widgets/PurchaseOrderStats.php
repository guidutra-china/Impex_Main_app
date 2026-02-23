<?php

namespace App\Filament\Resources\PurchaseOrders\Widgets;

use App\Domain\Infrastructure\Support\Money;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrderStats extends BaseWidget
{
    protected static bool $isLazy = false;

    public ?Model $record = null;

    protected function getStats(): array
    {
        if (! $this->record instanceof PurchaseOrder) {
            return [];
        }

        $po = $this->record;
        $po->loadMissing(['items', 'paymentScheduleItems']);

        $currency = $po->currency_code ?? 'USD';
        $total = $po->total;
        $paid = $po->schedule_paid_total;
        $remaining = $po->schedule_remaining;
        $progress = $po->payment_progress;
        $itemCount = $po->items->count();

        return [
            Stat::make('PO Total', $currency . ' ' . Money::format($total))
                ->description($itemCount . ' item(s)')
                ->icon('heroicon-o-shopping-cart')
                ->color('primary'),
            Stat::make('Paid', $currency . ' ' . Money::format($paid))
                ->description($progress . '% paid')
                ->icon('heroicon-o-banknotes')
                ->color($progress >= 100 ? 'success' : ($progress > 0 ? 'info' : 'gray')),
            Stat::make('Remaining', $currency . ' ' . Money::format($remaining))
                ->description($remaining <= 0 ? 'Fully paid' : 'Outstanding')
                ->icon('heroicon-o-clock')
                ->color($remaining <= 0 ? 'success' : 'warning'),
        ];
    }
}
