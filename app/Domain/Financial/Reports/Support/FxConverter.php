<?php

namespace App\Domain\Financial\Reports\Support;

use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Settings\Enums\ExchangeRateStatus;
use App\Domain\Settings\Models\ExchangeRate;
use Carbon\CarbonImmutable;

/**
 * Converts monetary amounts from a source currency to a presentation currency
 * using a pre-fetched rate cache. Returns null when no applicable rate exists,
 * which the caller must surface in the UI ("FX indisponivel").
 *
 * Cache key format: "{FROM}>{TO}|{YYYY-MM-DD}" (example: "USD>BRL|2026-03-15").
 * Values are decimal multipliers (float).
 */
final class FxConverter
{
    /**
     * @param  array<string, float>  $ratesCache  Pre-fetched rates keyed as "FROM>TO|YYYY-MM-DD".
     */
    public function __construct(
        private readonly string $presentationCurrency,
        private readonly array $ratesCache,
    ) {}

    public function convertDocument(int $amount, string $from, CarbonImmutable $at): ?int
    {
        if ($from === $this->presentationCurrency) {
            return $amount;
        }

        $rate = $this->findRate($from, $this->presentationCurrency, $at);
        if ($rate === null) {
            return null;
        }

        return (int) round($amount * $rate);
    }

    public function convertPayment(PaymentAllocation $allocation): ?int
    {
        $docAmount = (int) ($allocation->allocated_amount_in_document_currency ?? 0);
        $docCurrency = $allocation->scheduleItem?->currency_code
            ?? $allocation->payment?->currency_code;

        if ($docCurrency === null) {
            return null;
        }

        if ($allocation->payment === null || $allocation->payment->payment_date === null) {
            return null;
        }

        $paymentDate = $allocation->payment->payment_date instanceof CarbonImmutable
            ? $allocation->payment->payment_date
            : CarbonImmutable::parse((string) $allocation->payment->payment_date);

        return $this->convertDocument($docAmount, $docCurrency, $paymentDate);
    }

    private function findRate(string $from, string $to, CarbonImmutable $at): ?float
    {
        $prefix = "{$from}>{$to}|";
        $atKey = $at->format('Y-m-d');

        $bestDate = null;
        $bestRate = null;
        foreach ($this->ratesCache as $key => $rate) {
            if (! str_starts_with($key, $prefix)) {
                continue;
            }
            $date = substr($key, strlen($prefix));
            if ($date > $atKey) {
                continue;
            }
            if ($bestDate === null || $date > $bestDate) {
                $bestDate = $date;
                $bestRate = $rate;
            }
        }

        return $bestRate;
    }

    /**
     * Fetch approved ExchangeRate rows for the given (fromCurrency, date) pairs
     * and return them keyed in the cache format this converter expects.
     *
     * @param  list<array{from: string, at: CarbonImmutable}>  $needed
     * @return array<string, float>
     */
    public static function prefetchCache(array $needed, string $presentationCurrency): array
    {
        if (empty($needed) || $presentationCurrency === '') {
            return [];
        }

        $distinctFrom = array_values(array_unique(array_map(fn ($n) => $n['from'], $needed)));
        $distinctFrom = array_values(array_filter($distinctFrom, fn ($c) => $c !== $presentationCurrency));
        if (empty($distinctFrom)) {
            return [];
        }

        $currencies = \App\Domain\Settings\Models\Currency::query()
            ->whereIn('code', array_merge($distinctFrom, [$presentationCurrency]))
            ->pluck('id', 'code');

        $targetId = $currencies[$presentationCurrency] ?? null;
        if ($targetId === null) {
            return [];
        }

        $fromIds = collect($distinctFrom)->map(fn ($c) => $currencies[$c] ?? null)->filter()->values();
        if ($fromIds->isEmpty()) {
            return [];
        }

        $rates = ExchangeRate::query()
            ->whereIn('base_currency_id', $fromIds)
            ->where('target_currency_id', $targetId)
            ->where('status', ExchangeRateStatus::APPROVED)
            ->orderBy('date')
            ->get(['base_currency_id', 'target_currency_id', 'rate', 'date']);

        $codeById = $currencies->flip();
        $cache = [];
        foreach ($rates as $r) {
            $fromCode = $codeById[$r->base_currency_id] ?? null;
            if ($fromCode === null) {
                continue;
            }
            $date = $r->date instanceof \DateTimeInterface
                ? $r->date->format('Y-m-d')
                : (string) $r->date;
            $cache["{$fromCode}>{$presentationCurrency}|{$date}"] = (float) $r->rate;
        }

        return $cache;
    }
}
