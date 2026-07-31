<?php

namespace App\Filament\Pages\Widgets;

use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Financial\Support\CurrencyTotals;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Header totals for the AR/AP open-item worklists: total open and total
 * overdue, broken down by currency. Subclasses supply the scoped query.
 */
abstract class OpenItemsTotalsWidget extends StatsOverviewWidget
{
    abstract protected function openItemsQuery(): Builder;

    protected function getStats(): array
    {
        /** @var Collection<int, PaymentScheduleItem> $items */
        $items = $this->openItemsQuery()->get();

        $today = CarbonImmutable::now()->startOfDay();

        $open = CurrencyTotals::byCurrency($items);
        $overdue = CurrencyTotals::byCurrency(
            $items->filter(fn (PaymentScheduleItem $i) => $i->due_date !== null
                && CarbonImmutable::parse($i->due_date)->startOfDay()->lt($today))
        );

        return [
            Stat::make(__('open_items.totals.open'), CurrencyTotals::format($open)),
            Stat::make(__('open_items.totals.overdue'), CurrencyTotals::format($overdue))
                ->color($overdue->isEmpty() ? 'gray' : 'danger'),
        ];
    }
}
