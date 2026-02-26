<?php

namespace App\Domain\Settings\Console;

use App\Domain\Settings\Enums\ExchangeRateSource;
use App\Domain\Settings\Enums\ExchangeRateStatus;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FetchExchangeRatesCommand extends Command
{
    protected $signature = 'exchange-rates:fetch
        {--date= : Specific date to fetch (YYYY-MM-DD). Defaults to today.}
        {--auto-approve : Automatically approve fetched rates instead of pending.}';

    protected $description = 'Fetch latest exchange rates from Frankfurter API (European Central Bank data)';

    private const API_BASE_URL = 'https://api.frankfurter.dev/v1';

    public function handle(): int
    {
        $baseCurrency = Currency::base();

        if (! $baseCurrency) {
            $this->error('No base currency configured. Set a currency as base first.');
            Log::error('FetchExchangeRates: No base currency configured.');

            return self::FAILURE;
        }

        $targetCurrencies = Currency::where('is_active', true)
            ->where('id', '!=', $baseCurrency->id)
            ->get();

        if ($targetCurrencies->isEmpty()) {
            $this->warn('No active target currencies found. Nothing to fetch.');

            return self::SUCCESS;
        }

        $date = $this->option('date') ?? now()->toDateString();
        $autoApprove = $this->option('auto-approve');

        $this->info("Fetching exchange rates for {$baseCurrency->code} on {$date}...");

        $targetCodes = $targetCurrencies->pluck('code')->implode(',');

        try {
            $response = Http::timeout(15)
                ->retry(3, 2000)
                ->get(self::API_BASE_URL . '/latest', [
                    'base' => $baseCurrency->code,
                    'symbols' => $targetCodes,
                ]);

            if (! $response->successful()) {
                $this->error("API returned HTTP {$response->status()}");
                Log::error('FetchExchangeRates: API error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return self::FAILURE;
            }

            $data = $response->json();

            if (! isset($data['rates']) || empty($data['rates'])) {
                $this->error('API returned empty rates.');
                Log::error('FetchExchangeRates: Empty rates response', ['data' => $data]);

                return self::FAILURE;
            }

            $apiDate = $data['date'] ?? $date;
            $created = 0;
            $skipped = 0;
            $updated = 0;

            foreach ($targetCurrencies as $targetCurrency) {
                $rate = $data['rates'][$targetCurrency->code] ?? null;

                if ($rate === null) {
                    $this->warn("  No rate for {$targetCurrency->code} — skipped.");
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
                            'deleted_at' => null,
                        ]);
                        $this->line("  {$baseCurrency->code}/{$targetCurrency->code}: {$rate} (updated)");
                        $updated++;
                    } else {
                        $this->line("  {$baseCurrency->code}/{$targetCurrency->code}: {$rate} (unchanged)");
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
                    'status' => $autoApprove
                        ? ExchangeRateStatus::APPROVED->value
                        : ExchangeRateStatus::PENDING->value,
                    'approved_at' => $autoApprove ? now() : null,
                    'notes' => "Auto-fetched from Frankfurter API (ECB reference rates) on {$apiDate}",
                ]);

                $statusLabel = $autoApprove ? 'approved' : 'pending';
                $this->line("  {$baseCurrency->code}/{$targetCurrency->code}: {$rate} ({$statusLabel})");
                $created++;
            }

            $this->newLine();
            $this->info("Done. Created: {$created} | Updated: {$updated} | Skipped: {$skipped}");

            Log::info('FetchExchangeRates: Completed', [
                'base' => $baseCurrency->code,
                'date' => $apiDate,
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
            ]);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to fetch rates: {$e->getMessage()}");
            Log::error('FetchExchangeRates: Exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return self::FAILURE;
        }
    }
}
