<?php

namespace App\Domain\Financial\Support;

use App\Domain\Infrastructure\Support\Money;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use InvalidArgumentException;

/**
 * Canonical resolver for PaymentAllocation monetary values.
 *
 * A PaymentAllocation is a tuple of three values:
 *   - allocated_amount                        (payment currency, minor units)
 *   - allocated_amount_in_document_currency   (document currency, minor units)
 *   - exchange_rate                           (payment → document multiplier)
 *
 * The three values are algebraically tied: doc = pmt × rate.
 * When currencies differ, at least two of the three must be supplied by the
 * user; the missing third is derived. When currencies match, the allocation
 * degenerates to a single amount with no rate.
 *
 * This class is the single source of truth for that resolution. All four
 * allocation entry points (Create AR/AP, Edit AR/AP, View modal AR/AP, and
 * future surfaces) must go through {@see self::resolve()}.
 */
class AllocationCalculator
{
    /**
     * Resolve the three allocation values from user input.
     *
     * @param  string  $paymentCurrencyCode  ISO currency code of the payment (e.g. "BRL").
     * @param  string  $documentCurrencyCode  ISO currency code of the document being paid (e.g. "USD").
     * @param  float|null  $pmtMajor  Amount in payment currency (major units). Null if not supplied.
     * @param  float|null  $docMajor  Amount in document currency (major units). Null if not supplied.
     * @param  float|null  $rate  Exchange rate (pmt → doc). Null if not supplied.
     * @return array{
     *   allocated_amount: int,
     *   allocated_amount_in_document_currency: int,
     *   exchange_rate: float|null
     * }
     *
     * @throws InvalidArgumentException When insufficient data is provided.
     */
    public static function resolve(
        string $paymentCurrencyCode,
        string $documentCurrencyCode,
        ?float $pmtMajor,
        ?float $docMajor,
        ?float $rate
    ): array {
        $pmtMinor = ($pmtMajor !== null && $pmtMajor > 0) ? Money::toMinor($pmtMajor) : null;
        $docMinor = ($docMajor !== null && $docMajor > 0) ? Money::toMinor($docMajor) : null;
        $rate = ($rate !== null && $rate > 0) ? $rate : null;

        if ($paymentCurrencyCode === $documentCurrencyCode) {
            $amount = $pmtMinor ?? $docMinor;

            if ($amount === null) {
                throw new InvalidArgumentException(
                    'Allocation amount is required.'
                );
            }

            return [
                'allocated_amount' => $amount,
                'allocated_amount_in_document_currency' => $amount,
                'exchange_rate' => null,
            ];
        }

        $present = (int) ($pmtMinor !== null) + (int) ($docMinor !== null) + (int) ($rate !== null);

        if ($present < 2) {
            throw new InvalidArgumentException(
                'When the payment currency differs from the document currency, provide '
                .'at least two of: amount in payment currency, amount in document currency, '
                .'or exchange rate. No silent fallback will be applied.'
            );
        }

        if ($pmtMinor !== null && $docMinor !== null) {
            $rate = $docMinor / $pmtMinor;
        } elseif ($pmtMinor !== null && $rate !== null) {
            $docMinor = (int) round($pmtMinor * $rate);
        } elseif ($docMinor !== null && $rate !== null) {
            $pmtMinor = (int) round($docMinor / $rate);
        }

        return [
            'allocated_amount' => $pmtMinor,
            'allocated_amount_in_document_currency' => $docMinor,
            'exchange_rate' => $rate,
        ];
    }

    /**
     * Look up the current approved rate from payment currency to document
     * currency. Returns null when no approved rate chain is available, in
     * which case the caller must ask the user to supply rate/doc amount.
     */
    public static function lookupRate(string $paymentCurrencyCode, string $documentCurrencyCode): ?float
    {
        return self::lookupRateWithDate($paymentCurrencyCode, $documentCurrencyCode)['rate'];
    }

    /**
     * Same as lookupRate() but also returns the effective date of the
     * underlying ExchangeRate record (oldest date when triangulating).
     *
     * @return array{rate: ?float, date: ?string}
     */
    public static function lookupRateWithDate(string $paymentCurrencyCode, string $documentCurrencyCode): array
    {
        if ($paymentCurrencyCode === $documentCurrencyCode) {
            return ['rate' => 1.0, 'date' => null];
        }

        $pmt = Currency::where('code', $paymentCurrencyCode)->first();
        $doc = Currency::where('code', $documentCurrencyCode)->first();

        if (! $pmt || ! $doc) {
            return ['rate' => null, 'date' => null];
        }

        $rate = ExchangeRate::convert($pmt->id, $doc->id, 1.0);
        if ($rate === null) {
            return ['rate' => null, 'date' => null];
        }

        $base = Currency::base();
        $dates = [];
        if ($base) {
            if ($pmt->id !== $base->id) {
                $rec = ExchangeRate::getLatestRate($base->id, $pmt->id);
                if ($rec) {
                    $dates[] = $rec->date->toDateString();
                }
            }
            if ($doc->id !== $base->id) {
                $rec = ExchangeRate::getLatestRate($base->id, $doc->id);
                if ($rec) {
                    $dates[] = $rec->date->toDateString();
                }
            }
        }

        sort($dates);

        return ['rate' => $rate, 'date' => $dates[0] ?? null];
    }
}
