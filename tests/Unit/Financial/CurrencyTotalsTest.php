<?php

namespace Tests\Unit\Financial;

use App\Domain\Financial\Support\CurrencyTotals;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class CurrencyTotalsTest extends TestCase
{
    public function test_by_currency_groups_and_sums_remaining(): void
    {
        $items = new Collection([
            (object) ['currency_code' => 'USD', 'remaining_amount' => 1_000_000],
            (object) ['currency_code' => 'USD', 'remaining_amount' => 500_000],
            (object) ['currency_code' => 'CNY', 'remaining_amount' => 2_000_000],
            (object) ['currency_code' => 'BRL', 'remaining_amount' => 0],
        ]);

        $totals = CurrencyTotals::byCurrency($items);

        $this->assertSame(['USD' => 1_500_000, 'CNY' => 2_000_000], $totals->all());
    }

    public function test_format_joins_currencies_and_handles_empty(): void
    {
        $this->assertSame('—', CurrencyTotals::format(new Collection));
        $this->assertSame(
            'USD 150.00  ·  CNY 200.00',
            CurrencyTotals::format(new Collection(['USD' => 1_500_000, 'CNY' => 2_000_000])),
        );
    }
}
