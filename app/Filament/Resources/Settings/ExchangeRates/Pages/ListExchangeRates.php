<?php

namespace App\Filament\Resources\Settings\ExchangeRates\Pages;

use App\Domain\Settings\Enums\ExchangeRateSource;
use App\Domain\Settings\Enums\ExchangeRateStatus;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use App\Filament\Resources\Settings\ExchangeRates\ExchangeRateResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ListExchangeRates extends ListRecords
{
    protected static string $resource = ExchangeRateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('fetchRates')
                ->label(__('messages.exchange_rates.fetch_rates'))
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading(__('messages.exchange_rates.fetch_rates'))
                ->modalDescription(__('messages.exchange_rates.fetch_confirmation'))
                ->modalSubmitActionLabel(__('messages.exchange_rates.fetch_now'))
                ->action(function () {
                    $this->fetchRatesFromApi();
                }),
            CreateAction::make(),
        ];
    }

    private function fetchRatesFromApi(): void
    {
        $baseCurrency = Currency::base();

        if (! $baseCurrency) {
            Notification::make()
                ->title(__('messages.exchange_rates.no_base_currency'))
                ->body(__('messages.exchange_rates.no_base_currency_body'))
                ->danger()
                ->send();

            return;
        }

        $targetCurrencies = Currency::where('is_active', true)
            ->where('id', '!=', $baseCurrency->id)
            ->get();

        if ($targetCurrencies->isEmpty()) {
            Notification::make()
                ->title(__('messages.exchange_rates.no_target_currencies'))
                ->warning()
                ->send();

            return;
        }

        $targetCodes = $targetCurrencies->pluck('code')->implode(',');

        try {
            $response = Http::timeout(15)
                ->retry(3, 2000)
                ->get('https://api.frankfurter.dev/v1/latest', [
                    'base' => $baseCurrency->code,
                    'symbols' => $targetCodes,
                ]);

            if (! $response->successful()) {
                Notification::make()
                    ->title(__('messages.exchange_rates.api_error'))
                    ->body("HTTP {$response->status()}")
                    ->danger()
                    ->send();

                Log::error('FetchExchangeRates UI: API error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return;
            }

            $data = $response->json();

            if (! isset($data['rates']) || empty($data['rates'])) {
                Notification::make()
                    ->title(__('messages.exchange_rates.api_empty'))
                    ->danger()
                    ->send();

                return;
            }

            $apiDate = $data['date'] ?? now()->toDateString();
            $created = 0;
            $updated = 0;
            $skipped = 0;

            foreach ($targetCurrencies as $targetCurrency) {
                $rate = $data['rates'][$targetCurrency->code] ?? null;

                if ($rate === null) {
                    $skipped++;

                    continue;
                }

                $inverseRate = $rate > 0 ? round(1 / $rate, 8) : null;

                $existing = ExchangeRate::withTrashed()
                    ->where('base_currency_id', $baseCurrency->id)
                    ->where('target_currency_id', $targetCurrency->id)
                    ->where('date', $apiDate)
                    ->where('source', ExchangeRateSource::API->value)
                    ->first();

                if ($existing) {
                    if ((float) $existing->rate !== round($rate, 8)) {
                        $existing->update([
                            'rate' => round($rate, 8),
                            'inverse_rate' => $inverseRate,
                            'status' => ExchangeRateStatus::APPROVED->value,
                            'approved_at' => now(),
                            'deleted_at' => null,
                        ]);
                        $updated++;
                    } else {
                        $skipped++;
                    }

                    continue;
                }

                ExchangeRate::create([
                    'base_currency_id' => $baseCurrency->id,
                    'target_currency_id' => $targetCurrency->id,
                    'rate' => round($rate, 8),
                    'inverse_rate' => $inverseRate,
                    'date' => $apiDate,
                    'source' => ExchangeRateSource::API->value,
                    'source_name' => 'Frankfurter (ECB)',
                    'status' => ExchangeRateStatus::APPROVED->value,
                    'approved_at' => now(),
                    'notes' => "Fetched via UI from Frankfurter API (ECB) on {$apiDate}",
                ]);

                $created++;
            }

            Notification::make()
                ->title(__('messages.exchange_rates.fetch_success'))
                ->body(
                    __('messages.exchange_rates.fetch_summary', [
                        'date' => $apiDate,
                        'created' => $created,
                        'updated' => $updated,
                        'skipped' => $skipped,
                    ])
                )
                ->success()
                ->send();

            Log::info('FetchExchangeRates UI: Completed', [
                'base' => $baseCurrency->code,
                'date' => $apiDate,
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
            ]);
        } catch (\Exception $e) {
            Notification::make()
                ->title(__('messages.exchange_rates.fetch_failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();

            Log::error('FetchExchangeRates UI: Exception', [
                'message' => $e->getMessage(),
            ]);
        }
    }
}
