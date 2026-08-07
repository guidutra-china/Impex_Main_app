<?php

namespace App\Filament\Widgets\Financial;

use App\Domain\Financial\Support\CurrencyTotals;
use App\Domain\Financial\Support\OpenItemsSnapshot;
use App\Domain\Infrastructure\Support\Money;
use App\Filament\Widgets\Financial\Concerns\HasFinancialDashboardGate;
use Filament\Widgets\ChartWidget;

/**
 * Open AR vs. AP grouped per currency, in each currency's native units —
 * deliberately NOT converted, so the FX exposure per currency is visible.
 */
class CurrencyExposureChart extends ChartWidget
{
    use HasFinancialDashboardGate;

    protected static ?int $sort = 8;

    protected static bool $isLazy = true;

    protected int|string|array $columnSpan = [
        'default' => 12,
        'xl' => 6,
    ];

    protected ?string $maxHeight = '200px';

    protected ?string $pollingInterval = null;

    public function getHeading(): string
    {
        return __('widgets.financial_dashboard.currency_exposure_heading');
    }

    protected function getData(): array
    {
        $snapshot = app(OpenItemsSnapshot::class);
        $ar = CurrencyTotals::byCurrency($snapshot->receivables());
        $ap = CurrencyTotals::byCurrency($snapshot->payables());

        $currencies = $ar->keys()->concat($ap->keys())->unique()->sort()->values();

        return [
            'datasets' => [
                [
                    'label' => __('widgets.financial_dashboard.receivables'),
                    'data' => $currencies->map(fn (string $code) => Money::toMajor($ar[$code] ?? 0))->all(),
                    'backgroundColor' => '#22c55e',
                ],
                [
                    'label' => __('widgets.financial_dashboard.payables'),
                    'data' => $currencies->map(fn (string $code) => Money::toMajor($ap[$code] ?? 0))->all(),
                    'backgroundColor' => '#60a5fa',
                ],
            ],
            'labels' => $currencies->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
