<?php

declare(strict_types=1);

namespace App\Domain\AI\Tools\Concerns;

/**
 * Formats integer minor-unit amounts (cents) into a human string with currency,
 * e.g. (123456, 'USD') => "USD 1,234.56". Tools must never return raw ints/floats.
 */
trait FormatsMoney
{
    protected function formatMoney(?int $minorUnits, ?string $currencyCode): string
    {
        $amount = number_format(($minorUnits ?? 0) / 100, 2);
        $prefix = ($currencyCode !== null && $currencyCode !== '')
            ? strtoupper($currencyCode).' '
            : '';

        return $prefix.$amount;
    }
}
