<?php

namespace App\Filament\Resources\Finance\Trips\Support;

use App\Domain\Settings\Models\Currency;
use App\Domain\Travel\Models\Trip;
use App\Domain\Travel\Support\TripFx;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Set;

/**
 * Reusable Filament form fragment: a target/billing currency picker plus an
 * editable, indicative FX rate per expense currency on the trip. Shared by the
 * approval (debit note) and the expense report actions.
 */
class TripFxForm
{
    /**
     * @return array<int, \Filament\Forms\Components\Component>
     */
    public static function schema(Trip $trip, string $currencyKey, string $currencyLabel): array
    {
        $currencies = Currency::query()->orderBy('code')->pluck('code', 'code');
        if ($currencies->isEmpty()) {
            $currencies = collect(['USD' => 'USD', 'BRL' => 'BRL', 'CNY' => 'CNY']);
        }

        $defaultCurrency = Currency::base()?->code ?? $currencies->keys()->first();
        $sources = TripFx::distinctCurrencies($trip);

        $fields = [
            Select::make($currencyKey)
                ->label($currencyLabel)
                ->options($currencies)
                ->default($defaultCurrency)
                ->required()
                ->live()
                ->afterStateUpdated(function ($state, Set $set) use ($sources) {
                    // Refresh the indicative rates when the target currency changes.
                    foreach ($sources as $code) {
                        $set("rates.{$code}", static::formatRate(TripFx::indicativeRate($code, $state)));
                    }
                }),
        ];

        foreach ($sources as $code) {
            $fields[] = TextInput::make("rates.{$code}")
                ->label(__('forms.labels.fx_rate', ['from' => $code]))
                ->numeric()
                ->step('0.00000001')
                ->minValue(0)
                ->required()
                ->default(static::formatRate(TripFx::indicativeRate($code, $defaultCurrency)))
                ->helperText(__('forms.helpers.indicative_rate'));
        }

        return $fields;
    }

    /**
     * @return array<string, float>
     */
    public static function resolveRates(array $data): array
    {
        $rates = [];
        foreach ((array) ($data['rates'] ?? []) as $code => $rate) {
            $rates[$code] = (float) $rate;
        }

        return $rates;
    }

    private static function formatRate(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 8, '.', ''), '0'), '.') ?: '0';
    }
}
