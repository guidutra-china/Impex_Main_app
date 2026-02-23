<?php

namespace App\Filament\Resources\ProformaInvoices\Widgets;

use App\Domain\Infrastructure\Support\Money;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Model;

class ProformaInvoiceStats extends BaseWidget
{
    protected static bool $isLazy = false;

    public ?Model $record = null;

    protected function getStats(): array
    {
        if (! $this->record instanceof ProformaInvoice) {
            return [];
        }

        $pi = $this->record;
        $pi->loadMissing(['items', 'paymentScheduleItems']);

        $currency = $pi->currency_code ?? 'USD';
        $total = $pi->total;
        $costTotal = $pi->cost_total;
        $margin = $pi->margin;
        $paid = $pi->schedule_paid_total;
        $remaining = $pi->schedule_remaining;
        $progress = $pi->payment_progress;
        $itemCount = $pi->items->count();

        return [
            Stat::make('Invoice Total', $currency . ' ' . Money::format($total))
                ->description($itemCount . ' item(s)')
                ->icon('heroicon-o-document-currency-dollar')
                ->color('primary'),
            Stat::make('Cost Total', $currency . ' ' . Money::format($costTotal))
                ->description('Margin: ' . $margin . '%')
                ->icon('heroicon-o-calculator')
                ->color($margin > 0 ? 'success' : 'danger'),
            Stat::make('Paid', $currency . ' ' . Money::format($paid))
                ->description($progress . '% received')
                ->icon('heroicon-o-banknotes')
                ->color($progress >= 100 ? 'success' : ($progress > 0 ? 'info' : 'gray')),
            Stat::make('Remaining', $currency . ' ' . Money::format($remaining))
                ->description($remaining <= 0 ? 'Fully paid' : 'Outstanding')
                ->icon('heroicon-o-clock')
                ->color($remaining <= 0 ? 'success' : 'warning'),
        ];
    }
}
