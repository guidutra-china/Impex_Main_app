<?php

namespace Tests\Unit;

use App\Domain\Infrastructure\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    // === toMinor ===

    public function test_to_minor_converts_whole_dollars(): void
    {
        $this->assertEquals(1000000, Money::toMinor(100.00));
    }

    public function test_to_minor_converts_cents(): void
    {
        $this->assertEquals(155678, Money::toMinor(15.5678));
    }

    public function test_to_minor_handles_zero(): void
    {
        $this->assertEquals(0, Money::toMinor(0));
    }

    public function test_to_minor_handles_null(): void
    {
        $this->assertEquals(0, Money::toMinor(null));
    }

    public function test_to_minor_handles_negative_values(): void
    {
        $this->assertEquals(-100000, Money::toMinor(-10.00));
    }

    public function test_to_minor_handles_very_small_amounts(): void
    {
        $this->assertEquals(1, Money::toMinor(0.0001));
    }

    public function test_to_minor_handles_large_amounts(): void
    {
        $this->assertEquals(10000000000, Money::toMinor(1000000.00));
    }

    public function test_to_minor_handles_string_input(): void
    {
        $this->assertEquals(155678, Money::toMinor('15.5678'));
    }

    // === toMajor ===

    public function test_to_major_converts_back_to_dollars(): void
    {
        $this->assertEquals(100.00, Money::toMajor(1000000));
    }

    public function test_to_major_preserves_four_decimal_places(): void
    {
        $this->assertEquals(15.5678, Money::toMajor(155678));
    }

    public function test_to_major_handles_zero(): void
    {
        $this->assertEquals(0.0, Money::toMajor(0));
    }

    public function test_to_major_handles_null(): void
    {
        $this->assertEquals(0.0, Money::toMajor(null));
    }

    public function test_to_major_handles_negative(): void
    {
        $this->assertEquals(-10.00, Money::toMajor(-100000));
    }

    // === Roundtrip consistency ===

    public function test_roundtrip_conversion_preserves_value(): void
    {
        $values = [0.01, 0.10, 1.00, 15.5678, 100.00, 999999.9999, 0.0001];

        foreach ($values as $value) {
            $minor = Money::toMinor($value);
            $major = Money::toMajor($minor);
            $this->assertEquals($value, $major, "Roundtrip failed for {$value}");
        }
    }

    // === format ===

    public function test_format_displays_two_decimal_places_by_default(): void
    {
        // 1555500 minor = 155.55 major (4 decimal minor units: /10000)
        $formatted = Money::format(1555500);
        $this->assertEquals('155.55', $formatted);
    }

    public function test_format_with_four_decimals(): void
    {
        $formatted = Money::format(1555567, 4);
        $this->assertEquals('155.5567', $formatted);
    }

    public function test_format_large_amount(): void
    {
        // $15,555.00 = 155550000 minor units
        $formatted = Money::format(155550000);
        $this->assertEquals('15,555.00', $formatted);
    }

    public function test_format_handles_zero(): void
    {
        $formatted = Money::format(0);
        $this->assertEquals('0.00', $formatted);
    }

    public function test_format_handles_null(): void
    {
        $formatted = Money::format(null);
        $this->assertEquals('0.00', $formatted);
    }

    // === Arithmetic edge cases ===

    public function test_floating_point_precision_not_lost(): void
    {
        // Classic floating point trap: 0.1 + 0.2 != 0.3
        $a = Money::toMinor(0.1);
        $b = Money::toMinor(0.2);
        $sum = $a + $b;
        $this->assertEquals(Money::toMinor(0.3), $sum);
    }

    public function test_percentage_calculation_accuracy(): void
    {
        // 30% of $1,000.00
        $total = Money::toMinor(1000.00);
        $percentage = (int) round($total * (30 / 100));
        $this->assertEquals(Money::toMinor(300.00), $percentage);
    }

    public function test_percentage_calculation_with_remainder(): void
    {
        // 33.33% of $100.00 - common in 3-stage payment terms
        $total = Money::toMinor(100.00);
        $percentage = (int) round($total * (33.33 / 100));
        // 33.33% of 1000000 = 333300
        $this->assertEquals(333300, $percentage);
    }

    public function test_three_way_split_sums_correctly(): void
    {
        // 33.33% + 33.33% + 33.34% should equal 100%
        $total = Money::toMinor(100.00);
        $part1 = (int) round($total * (33.33 / 100));
        $part2 = (int) round($total * (33.33 / 100));
        $part3 = (int) round($total * (33.34 / 100));
        // Allow 1 unit difference due to rounding
        $this->assertEqualsWithDelta($total, $part1 + $part2 + $part3, 1);
    }

    public function test_line_total_calculation(): void
    {
        // 500 units at $12.50 each
        $unitPrice = Money::toMinor(12.50);
        $quantity = 500;
        $lineTotal = $unitPrice * $quantity;
        $this->assertEquals(Money::toMinor(6250.00), $lineTotal);
    }

    public function test_margin_calculation(): void
    {
        // Buy at $10.00, sell at $15.00 = 33.33% margin
        $buyPrice = Money::toMinor(10.00);
        $sellPrice = Money::toMinor(15.00);
        $marginPercent = (($sellPrice - $buyPrice) / $sellPrice) * 100;
        $this->assertEqualsWithDelta(33.33, $marginPercent, 0.01);
    }

    public function test_markup_calculation(): void
    {
        // Buy at $10.00, sell at $15.00 = 50% markup
        $buyPrice = Money::toMinor(10.00);
        $sellPrice = Money::toMinor(15.00);
        $markupPercent = (($sellPrice - $buyPrice) / $buyPrice) * 100;
        $this->assertEqualsWithDelta(50.00, $markupPercent, 0.01);
    }
}
