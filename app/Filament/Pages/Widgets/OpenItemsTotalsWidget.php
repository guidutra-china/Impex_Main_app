<?php

namespace App\Filament\Pages\Widgets;

use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Financial\Support\CurrencyTotals;
use Carbon\CarbonImmutable;
use Filament\Widgets\Concerns\InteractsWithPageTable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Collection;

/**
 * Header totals for the AR/AP open-item worklists: total open and total
 * overdue, broken down by currency.
 *
 * Reads the page's own filtered table query, so the totals always describe
 * exactly the rows the user is looking at — counterparty, currency, status,
 * aging and the search box all narrow them. Subclasses name the page.
 */
abstract class OpenItemsTotalsWidget extends StatsOverviewWidget
{
    use InteractsWithPageTable;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        /** @var Collection<int, PaymentScheduleItem> $items */
        $items = $this->getPageTableQuery()->get();

        $today = CarbonImmutable::now()->startOfDay();

        $open = CurrencyTotals::byCurrency($items);
        $overdue = CurrencyTotals::byCurrency(
            $items->filter(fn (PaymentScheduleItem $i) => $i->isOverdueAsOf($today))
        );

        return [
            Stat::make(__('open_items.totals.open'), CurrencyTotals::format($open)),
            Stat::make(__('open_items.totals.overdue'), CurrencyTotals::format($overdue))
                ->color($overdue->isEmpty() ? 'gray' : 'danger'),
        ];
    }
}
