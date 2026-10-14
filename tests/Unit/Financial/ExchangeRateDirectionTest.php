<?php

namespace Tests\Unit\Financial;

use App\Domain\Financial\Support\ExchangeRateDirection;
use PHPUnit\Framework\TestCase;

class ExchangeRateDirectionTest extends TestCase
{
    public function test_stored_value_is_unchanged_when_not_inverted(): void
    {
        $this->assertSame('0.20000000', ExchangeRateDirection::toStored('0.2', false));
        $this->assertSame('0.20000000', ExchangeRateDirection::toDisplayed('0.2', false));
    }

    public function test_inverted_display_round_trips_to_the_stored_direction(): void
    {
        // Usuário digita USD → BRL = 5; gravamos BRL → USD = 0.2.
        $this->assertSame('0.20000000', ExchangeRateDirection::toStored('5', true));
        // Gravado 0.2 aparece como 5 quando a linha está invertida.
        $this->assertSame('5.00000000', ExchangeRateDirection::toDisplayed('0.20000000', true));
    }

    public function test_invert_flips_the_value_and_keeps_blanks(): void
    {
        $this->assertSame('5.00000000', ExchangeRateDirection::invert('0.2'));
        $this->assertSame('0.18181818', ExchangeRateDirection::invert('5.5'));
        $this->assertNull(ExchangeRateDirection::invert(null));
        $this->assertNull(ExchangeRateDirection::invert(''));
        $this->assertNull(ExchangeRateDirection::invert('0'));
        $this->assertNull(ExchangeRateDirection::invert('abc'));
    }
}
