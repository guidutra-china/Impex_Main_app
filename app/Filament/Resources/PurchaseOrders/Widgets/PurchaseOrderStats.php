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

        $scheduleItems = $po->paymentScheduleItems;
        $regularItems = $scheduleItems->where('is_credit', false);
        $creditItems = $scheduleItems->where('is_credit', true);

        $totalDue = $regularItems->sum('amount');
        $totalCredits = $creditItems->sum(fn ($i) => abs($i->amount));
        $netDue = $totalDue - $totalCredits;
        $totalPaid = $regularItems->sum(fn ($i) => $i->paid_amount);
        $netRemaining = max(0, $netDue - $totalPaid);
        $progress = $netDue > 0 ? round(($totalPaid / $netDue) * 100) : 0;

        $stats = [
            Stat::make('PO Total', $currency . ' ' . Money::format($total))
                ->description($totalCredits > 0
                    ? 'Credits: ' . $currency . ' ' . Money::format($totalCredits)
                    : $po->items->count() . ' item(s)')
                ->icon('heroicon-o-shopping-cart')
                ->color('primary'),
            Stat::make('Paid', $currency . ' ' . Money::format($totalPaid))
                ->description($progress . '% paid')
                ->icon('heroicon-o-banknotes')
                ->color($progress >= 100 ? 'success' : ($progress > 0 ? 'info' : 'gray')),
            Stat::make('Remaining', $currency . ' ' . Money::format($netRemaining))
                ->description($netRemaining <= 0 ? 'Fully paid' : 'Outstanding')
                ->icon('heroicon-o-clock')
                ->color($netRemaining <= 0 ? 'success' : 'warning'),
        ];

        return $stats;
    }
}
