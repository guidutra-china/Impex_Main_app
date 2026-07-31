<?php

namespace App\Filament\Widgets\Financial;

use App\Domain\Financial\Queries\AgingBucketsQuery;
use App\Domain\Infrastructure\Support\Money;
use Filament\Widgets\ChartWidget;

/**
 * Stacked horizontal bar: one row per currency, one colored segment per aging
 * bucket. Subclasses supply the bucket data (AR or AP side).
 */
abstract class AgingChart extends ChartWidget
{
    protected static bool $isLazy = true;

    protected int|string|array $columnSpan = [
        'default' => 12,
        'xl' => 6,
    ];

    protected ?string $pollingInterval = null;

    /** @return array<string, array<string, int>> */
    abstract protected function buckets(): array;

    public static function canView(): bool
    {
        return auth()->user()?->can('view-financial-dashboard') ?? false;
    }

    protected function getData(): array
    {
        $buckets = $this->buckets();

        $currencies = collect($buckets)
            ->flatMap(fn (array $byCurrency) => array_keys($byCurrency))
            ->unique()
            ->sort()
            ->values();

        $colors = [
            'current' => '#22c55e',
            'd1_30' => '#facc15',
            'd31_60' => '#fb923c',
            'd60_plus' => '#ef4444',
        ];

        $labelKeys = [
            'current' => 'aging_current',
            'd1_30' => 'aging_1_30',
            'd31_60' => 'aging_31_60',
            'd60_plus' => 'aging_60_plus',
        ];

        return [
            'datasets' => collect(AgingBucketsQuery::BUCKETS)->map(fn (string $bucket) => [
                'label' => __('widgets.financial_dashboard.'.$labelKeys[$bucket]),
                'data' => $currencies->map(fn (string $code) => Money::toMajor($buckets[$bucket][$code] ?? 0))->all(),
                'backgroundColor' => $colors[$bucket],
            ])->all(),
            'labels' => $currencies->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'scales' => [
                'x' => ['stacked' => true],
                'y' => ['stacked' => true],
            ],
        ];
    }
}
