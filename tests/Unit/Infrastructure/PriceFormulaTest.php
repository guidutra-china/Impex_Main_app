<?php

namespace Tests\Unit\Infrastructure;

use App\Domain\Infrastructure\Pdf\Support\PriceFormula;
use App\Domain\Infrastructure\Support\Money;
use PHPUnit\Framework\TestCase;

class PriceFormulaTest extends TestCase
{
    public function test_multiplication_and_division_operate_on_minor_units(): void
    {
        $hundred = 100 * Money::SCALE;

        $this->assertSame(70 * Money::SCALE, PriceFormula::apply($hundred, '*0.70'));
        $this->assertSame(50 * Money::SCALE, PriceFormula::apply($hundred, '/2'));
    }

    public function test_addition_and_subtraction_take_the_operand_in_major_units(): void
    {
        $hundred = 100 * Money::SCALE;

        $this->assertSame(110 * Money::SCALE, PriceFormula::apply($hundred, '+10'));
        $this->assertSame(95 * Money::SCALE, PriceFormula::apply($hundred, '-5'));
    }

    public function test_blank_or_malformed_formula_keeps_the_original_price(): void
    {
        $value = 12345;

        $this->assertSame($value, PriceFormula::apply($value, null));
        $this->assertSame($value, PriceFormula::apply($value, ''));
        $this->assertSame($value, PriceFormula::apply($value, 'x0.5'));
        $this->assertSame($value, PriceFormula::apply($value, 'drop table'));
    }

    public function test_division_by_zero_keeps_the_original_price(): void
    {
        $this->assertSame(5000, PriceFormula::apply(5000, '/0'));
    }

    public function test_spaces_between_operator_and_number_are_accepted(): void
    {
        $this->assertSame(50 * Money::SCALE, PriceFormula::apply(100 * Money::SCALE, '* 0.5'));
    }
}
