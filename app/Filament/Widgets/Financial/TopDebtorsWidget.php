<?php

namespace App\Filament\Widgets\Financial;

use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Queries\OpenScheduleItemsQuery;
use App\Domain\Financial\Support\BaseCurrency;
use App\Domain\Financial\Support\CurrencyTotals;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Settings\Models\Currency;
use Filament\Widgets\Widget;

class TopDebtorsWidget extends Widget
{
    protected static ?int $sort = 7;

    protected static bool $isLazy = true;

    protected string $view = 'filament.widgets.top-debtors';

    protected int|string|array $columnSpan = [
        'default' => 12,
        'xl' => 6,
    ];

    public static function canView(): bool
    {
        return auth()->user()?->can('view-financial-dashboard') ?? false;
    }

    protected function getViewData(): array
    {
        $items = OpenScheduleItemsQuery::receivables()->get();

        $unconverted = [];

        $debtors = $items
            ->groupBy(fn ($item) => $item->counterpartyName(PaymentDirection::INBOUND) ?? '—')
            ->map(function ($group) use (&$unconverted) {
                $result = BaseCurrency::convert(CurrencyTotals::byCurrency($group));
                $unconverted = array_merge($unconverted, $result['unconverted']);

                return $result['total'];
            })
            ->filter(fn (int $total) => $total > 0)
            ->sortDesc()
            ->take(5);

        $max = $debtors->max() ?: 1;

        return [
            'baseCurrencyCode' => Currency::base()?->code ?? '',
            'debtors' => $debtors->map(fn (int $total, string $name) => [
                'name' => $name,
                'formatted' => Money::formatDisplay($total),
                'percent' => (int) round($total / $max * 100),
            ])->values()->all(),
            'unconverted' => array_values(array_unique($unconverted)),
        ];
    }
}
