<?php

namespace App\Filament\Widgets\Financial;

use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Financial\Queries\OpenScheduleItemsQuery;
use App\Domain\Financial\Support\CurrencyTotals;
use App\Domain\Infrastructure\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Collection;

class FinancialKpisWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can('view-financial-dashboard') ?? false;
    }

    protected function getStats(): array
    {
        $receivables = OpenScheduleItemsQuery::receivables()->get();
        $payables = OpenScheduleItemsQuery::payables()->get();

        $today = CarbonImmutable::now()->startOfDay();
        $isOverdue = fn (PaymentScheduleItem $item): bool => $item->due_date !== null
            && CarbonImmutable::parse($item->due_date)->startOfDay()->lt($today);

        $arTotals = CurrencyTotals::byCurrency($receivables);
        $apTotals = CurrencyTotals::byCurrency($payables);
        $overdueTotals = CurrencyTotals::byCurrency(
            $receivables->filter($isOverdue)->concat($payables->filter($isOverdue))
        );

        $netTotals = $arTotals->keys()->concat($apTotals->keys())->unique()
            ->mapWithKeys(fn (string $code) => [
                $code => ($arTotals[$code] ?? 0) - ($apTotals[$code] ?? 0),
            ])
            ->filter(fn (int $total) => $total !== 0);

        return [
            Stat::make(__('widgets.financial_dashboard.receivables'), CurrencyTotals::format($arTotals))
                ->description(trans_choice('widgets.financial_dashboard.open_items_count', $receivables->count(), ['count' => $receivables->count()]))
                ->color('success'),
            Stat::make(__('widgets.financial_dashboard.payables'), CurrencyTotals::format($apTotals))
                ->description(trans_choice('widgets.financial_dashboard.open_items_count', $payables->count(), ['count' => $payables->count()]))
                ->color('info'),
            Stat::make(__('widgets.financial_dashboard.overdue'), CurrencyTotals::format($overdueTotals))
                ->color($overdueTotals->isEmpty() ? 'gray' : 'danger'),
            Stat::make(__('widgets.financial_dashboard.net_open'), $this->formatNet($netTotals))
                ->color('primary'),
        ];
    }

    /**
     * @param  Collection<string, int>  $netTotals
     */
    private function formatNet(Collection $netTotals): string
    {
        if ($netTotals->isEmpty()) {
            return '—';
        }

        return $netTotals
            ->map(fn (int $total, string $currency) => ($total < 0 ? '-' : '').$currency.' '.Money::formatDisplay(abs($total)))
            ->implode('  ·  ');
    }
}
