<?php

namespace App\Filament\Widgets\Financial;

use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Financial\Queries\OpenScheduleItemsQuery;
use App\Domain\Financial\Support\CurrencyTotals;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FinancialKpisWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can('view-financial-dashboard') ?? false;
    }

    protected function getStats(): array
    {
        $receivables = OpenScheduleItemsQuery::receivables()
            ->without(['payable', 'source', 'shipment'])
            ->get();
        $payables = OpenScheduleItemsQuery::payables()
            ->without(['payable', 'source', 'shipment'])
            ->get();

        $today = CarbonImmutable::now()->startOfDay();

        $arTotals = CurrencyTotals::byCurrency($receivables);
        $apTotals = CurrencyTotals::byCurrency($payables);
        $overdueTotals = CurrencyTotals::byCurrency(
            $receivables->filter(fn (PaymentScheduleItem $i) => $i->isOverdueAsOf($today))
                ->concat($payables->filter(fn (PaymentScheduleItem $i) => $i->isOverdueAsOf($today)))
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
            Stat::make(__('widgets.financial_dashboard.net_open'), CurrencyTotals::formatSigned($netTotals))
                ->color('primary'),
        ];
    }
}
