<?php

namespace Tests\Unit\Financial;

use App\Domain\Financial\Support\AllocationCalculator;
use App\Domain\Infrastructure\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class AllocationCalculatorTest extends TestCase
{
    // ==== Same-currency allocations ====

    public function test_same_currency_with_payment_amount_only(): void
    {
        $out = AllocationCalculator::resolve('USD', 'USD', 1000.00, null, null);

        $this->assertEquals(Money::toMinor(1000.00), $out['allocated_amount']);
        $this->assertEquals(Money::toMinor(1000.00), $out['allocated_amount_in_document_currency']);
        $this->assertNull($out['exchange_rate']);
    }

    public function test_same_currency_with_document_amount_only(): void
    {
        $out = AllocationCalculator::resolve('USD', 'USD', null, 1000.00, null);

        $this->assertEquals(Money::toMinor(1000.00), $out['allocated_amount']);
        $this->assertEquals(Money::toMinor(1000.00), $out['allocated_amount_in_document_currency']);
        $this->assertNull($out['exchange_rate']);
    }

    public function test_same_currency_rejects_missing_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AllocationCalculator::resolve('USD', 'USD', null, null, null);
    }

    // ==== Cross-currency, all three derivation paths ====

    public function test_cross_currency_with_pmt_and_rate_derives_doc(): void
    {
        // 1000 BRL × rate 0.2 = 200 USD
        $out = AllocationCalculator::resolve('BRL', 'USD', 1000.00, null, 0.2);

        $this->assertEquals(Money::toMinor(1000.00), $out['allocated_amount']);
        $this->assertEquals(Money::toMinor(200.00), $out['allocated_amount_in_document_currency']);
        $this->assertEquals(0.2, $out['exchange_rate']);
    }

    public function test_cross_currency_with_doc_and_rate_derives_pmt(): void
    {
        // 200 USD / rate 0.2 = 1000 BRL
        $out = AllocationCalculator::resolve('BRL', 'USD', null, 200.00, 0.2);

        $this->assertEquals(Money::toMinor(1000.00), $out['allocated_amount']);
        $this->assertEquals(Money::toMinor(200.00), $out['allocated_amount_in_document_currency']);
        $this->assertEquals(0.2, $out['exchange_rate']);
    }

    public function test_cross_currency_with_pmt_and_doc_derives_rate(): void
    {
        $out = AllocationCalculator::resolve('BRL', 'USD', 1000.00, 200.00, null);

        $this->assertEquals(Money::toMinor(1000.00), $out['allocated_amount']);
        $this->assertEquals(Money::toMinor(200.00), $out['allocated_amount_in_document_currency']);
        $this->assertEqualsWithDelta(0.2, $out['exchange_rate'], 1e-8);
    }

    public function test_cross_currency_with_all_three_respects_user_input(): void
    {
        // Even redundant, trust what user provided (rate = doc/pmt was already satisfied).
        $out = AllocationCalculator::resolve('BRL', 'USD', 1000.00, 200.00, 0.2);

        $this->assertEquals(Money::toMinor(1000.00), $out['allocated_amount']);
        $this->assertEquals(Money::toMinor(200.00), $out['allocated_amount_in_document_currency']);
        $this->assertEqualsWithDelta(0.2, $out['exchange_rate'], 1e-8);
    }

    // ==== Failure cases ====

    public function test_cross_currency_rejects_only_pmt(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AllocationCalculator::resolve('BRL', 'USD', 1000.00, null, null);
    }

    public function test_cross_currency_rejects_only_doc(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AllocationCalculator::resolve('BRL', 'USD', null, 200.00, null);
    }

    public function test_cross_currency_rejects_only_rate(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AllocationCalculator::resolve('BRL', 'USD', null, null, 0.2);
    }

    public function test_cross_currency_rejects_nothing(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AllocationCalculator::resolve('BRL', 'USD', null, null, null);
    }

    // ==== Regression guard: silent corruption bug ====

    /**
     * Regression guard for the bug where, before this refactor, supplying an
     * allocated_amount (BRL) with no exchange_rate and no approved
     * ExchangeRate row caused the raw BRL minor value to be written to
     * allocated_amount_in_document_currency as if it were USD. The new
     * calculator refuses to guess when data is insufficient.
     */
    public function test_cross_currency_fails_loud_without_conversion_data(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at least two of/i');

        AllocationCalculator::resolve('BRL', 'USD', 1000.00, null, null);
    }
}
