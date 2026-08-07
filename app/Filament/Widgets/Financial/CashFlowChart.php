<?php

namespace App\Filament\Widgets\Financial;

use App\Domain\Financial\Services\MonthlyCashFlowProjection;
use App\Domain\Settings\Models\Currency;
use App\Filament\Widgets\Financial\Concerns\HasFinancialDashboardGate;
use Filament\Widgets\ChartWidget;

class CashFlowChart extends ChartWidget
{
    use HasFinancialDashboardGate;

    protected static ?int $sort = 2;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '200px';

    protected ?string $pollingInterval = null;

    /** @var array{labels: array, inflow: array, outflow: array, net: array, has_warning: bool, unconverted: array}|null */
    private ?array $projection = null;

    public function getHeading(): string
    {
        return __('widgets.financial_dashboard.cash_flow_heading');
    }

    public function getDescription(): ?string
    {
        $data = $this->getProjection();

        if (! $data['has_warning']) {
            return Currency::base()?->code;
        }

        return __('widgets.financial_dashboard.conversion_warning', [
            'codes' => implode(', ', $data['unconverted']),
        ]);
    }

    protected function getData(): array
    {
        $data = $this->getProjection();

        return [
            'datasets' => [
                [
                    'type' => 'line',
                    'label' => __('widgets.financial_dashboard.cash_flow_net'),
                    'data' => $data['net'],
                    'borderColor' => '#6366f1',
                    'backgroundColor' => '#6366f1',
                ],
                [
                    'label' => __('widgets.financial_dashboard.cash_flow_inflow'),
                    'data' => $data['inflow'],
                    'backgroundColor' => '#22c55e',
                ],
                [
                    'label' => __('widgets.financial_dashboard.cash_flow_outflow'),
                    'data' => $data['outflow'],
                    'backgroundColor' => '#f87171',
                ],
            ],
            'labels' => $data['labels'],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /** @return array{labels: array, inflow: array, outflow: array, net: array, has_warning: bool, unconverted: array} */
    private function getProjection(): array
    {
        return $this->projection ??= (new MonthlyCashFlowProjection)->build();
    }
}
