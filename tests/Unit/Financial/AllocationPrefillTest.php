<?php

namespace Tests\Unit\Financial;

use App\Domain\Financial\Support\AllocationPrefill;
use App\Domain\Infrastructure\Support\Money;
use PHPUnit\Framework\TestCase;

class AllocationPrefillTest extends TestCase
{
    public function test_own_credit_is_applied_and_cash_is_the_net(): void
    {
        // PO-71: parcela 47.840,10, desconto 2.050,29 → dinheiro 45.789,81.
        $plan = AllocationPrefill::plan(
            Money::toMinor(47840.10),
            [['id' => 5, 'available' => Money::toMinor(2050.29)]],
        );

        $this->assertSame([['credit_schedule_item_id' => 5, 'credit_amount' => '2050.29']], $plan['credits']);
        $this->assertSame(Money::toMinor(45789.81), $plan['cash_minor']);
    }

    public function test_credit_is_capped_at_the_item_balance(): void
    {
        $plan = AllocationPrefill::plan(Money::toMinor(100), [['id' => 5, 'available' => Money::toMinor(150)]]);

        $this->assertSame('100.00', $plan['credits'][0]['credit_amount']);
        $this->assertSame(0, $plan['cash_minor']);
    }

    public function test_several_credits_stack_in_order_until_the_balance_is_covered(): void
    {
        $plan = AllocationPrefill::plan(Money::toMinor(100), [
            ['id' => 1, 'available' => Money::toMinor(30)],
            ['id' => 2, 'available' => Money::toMinor(90)],
            ['id' => 3, 'available' => Money::toMinor(10)],
        ]);

        $this->assertSame([
            ['credit_schedule_item_id' => 1, 'credit_amount' => '30.00'],
            ['credit_schedule_item_id' => 2, 'credit_amount' => '70.00'],
        ], $plan['credits']);
        $this->assertSame(0, $plan['cash_minor']);
    }

    public function test_credits_already_used_elsewhere_in_the_form_are_skipped(): void
    {
        $plan = AllocationPrefill::plan(Money::toMinor(100), [['id' => 5, 'available' => Money::toMinor(20)]], usedCreditIds: [5]);

        $this->assertSame([], $plan['credits']);
        $this->assertSame(Money::toMinor(100), $plan['cash_minor']);
    }

    public function test_no_credits_means_full_cash(): void
    {
        $plan = AllocationPrefill::plan(Money::toMinor(15733.20), []);

        $this->assertSame([], $plan['credits']);
        $this->assertSame(Money::toMinor(15733.20), $plan['cash_minor']);
    }
}
