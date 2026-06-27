<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\Domain\AI\Tools\Concerns\FormatsMoney;
use Tests\TestCase;

class FormatsMoneyTest extends TestCase
{
    private function subject(): object
    {
        return new class
        {
            use FormatsMoney;

            public function fmt(?int $minor, ?string $currency): string
            {
                return $this->formatMoney($minor, $currency);
            }
        };
    }

    public function test_formats_minor_units_with_currency(): void
    {
        $this->assertSame('USD 1,234.56', $this->subject()->fmt(123456, 'usd'));
    }

    public function test_handles_null_amount_and_currency(): void
    {
        $this->assertSame('0.00', $this->subject()->fmt(null, null));
    }
}
