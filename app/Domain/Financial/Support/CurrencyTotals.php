<?php

namespace App\Domain\Financial\Support;

use App\Domain\Infrastructure\Support\Money;
use Illuminate\Support\Collection;

/**
 * Per-currency totals for schedule items. Items only need `currency_code`
 * and `remaining_amount`, so both Eloquent models and plain objects work.
 */
final class CurrencyTotals
{
    /**
     * @param  Collection<int, object>  $items
     * @return Collection<string, int>
     */
    public static function byCurrency(Collection $items): Collection
    {
        return $items
            ->groupBy('currency_code')
            ->map(fn (Collection $group) => (int) $group->sum(fn (object $item) => $item->remaining_amount))
            ->filter(fn (int $total) => $total > 0);
    }

    /**
     * @param  Collection<string, int>  $byCurrency
     */
    public static function format(Collection $byCurrency): string
    {
        if ($byCurrency->isEmpty()) {
            return '—';
        }

        return $byCurrency
            ->map(fn (int $total, string $currency) => $currency.' '.Money::formatDisplay($total))
            ->implode('  ·  ');
    }

    /**
     * Like format(), but keeps negative totals visible with a sign prefix
     * (e.g. '-CNY 200.00') instead of assuming all totals are positive.
     *
     * @param  Collection<string, int>  $byCurrency
     */
    public static function formatSigned(Collection $byCurrency): string
    {
        if ($byCurrency->isEmpty()) {
            return '—';
        }

        return $byCurrency
            ->map(fn (int $total, string $currency) => ($total < 0 ? '-' : '').$currency.' '.Money::formatDisplay(abs($total)))
            ->implode('  ·  ');
    }
}
