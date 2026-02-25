<?php

namespace Tests\Unit;

use App\Domain\Infrastructure\Support\Money;
use PHPUnit\Framework\TestCase;

/**
 * Tests for payment schedule calculation logic.
 * These are pure math tests — no database needed.
 */
class PaymentScheduleTest extends TestCase
{
    // === Percentage allocation ===

    public function test_two_stage_split_30_70(): void
    {
        $total = Money::toMinor(10000.00);
        $stage1 = (int) round($total * (30 / 100));
        $stage2 = (int) round($total * (70 / 100));

        $this->assertEquals(Money::toMinor(3000.00), $stage1);
        $this->assertEquals(Money::toMinor(7000.00), $stage2);
        $this->assertEquals($total, $stage1 + $stage2);
    }

    public function test_three_stage_split_30_30_40(): void
    {
        $total = Money::toMinor(25000.00);
        $stage1 = (int) round($total * (30 / 100));
        $stage2 = (int) round($total * (30 / 100));
        $stage3 = (int) round($total * (40 / 100));

        $this->assertEquals($total, $stage1 + $stage2 + $stage3);
    }

    public function test_uneven_split_creates_rounding_difference(): void
    {
        // 33.33% + 33.33% + 33.34% of $1,000.00
        $total = Money::toMinor(1000.00);
        $stage1 = (int) round($total * (33.33 / 100));
        $stage2 = (int) round($total * (33.33 / 100));
        $stage3 = (int) round($total * (33.34 / 100));

        $sum = $stage1 + $stage2 + $stage3;
        // Must be within 1 minor unit of total
        $this->assertEqualsWithDelta($total, $sum, 1);
    }

    public function test_100_percent_single_payment(): void
    {
        $total = Money::toMinor(50000.00);
        $stage1 = (int) round($total * (100 / 100));

        $this->assertEquals($total, $stage1);
    }

    public function test_small_amount_split_does_not_lose_money(): void
    {
        // $1.00 split 50/50
        $total = Money::toMinor(1.00);
        $stage1 = (int) round($total * (50 / 100));
        $stage2 = (int) round($total * (50 / 100));

        $this->assertEquals($total, $stage1 + $stage2);
    }

    public function test_very_large_amount_split(): void
    {
        // $1,000,000.00 split 30/70
        $total = Money::toMinor(1000000.00);
        $stage1 = (int) round($total * (30 / 100));
        $stage2 = (int) round($total * (70 / 100));

        $this->assertEquals($total, $stage1 + $stage2);
    }

    // === Remaining balance calculation ===

    public function test_remaining_after_partial_payment(): void
    {
        $scheduleAmount = Money::toMinor(3000.00);
        $paidAmount = Money::toMinor(1500.00);
        $remaining = $scheduleAmount - $paidAmount;

        $this->assertEquals(Money::toMinor(1500.00), $remaining);
    }

    public function test_overpayment_detection(): void
    {
        $scheduleAmount = Money::toMinor(3000.00);
        $paidAmount = Money::toMinor(3500.00);
        $remaining = $scheduleAmount - $paidAmount;

        $this->assertTrue($remaining < 0, 'Overpayment should result in negative remaining');
    }

    public function test_exact_payment_zeroes_remaining(): void
    {
        $scheduleAmount = Money::toMinor(3000.00);
        $paidAmount = Money::toMinor(3000.00);
        $remaining = $scheduleAmount - $paidAmount;

        $this->assertEquals(0, $remaining);
    }

    // === Multi-currency edge cases ===

    public function test_different_decimal_precision_currencies(): void
    {
        // JPY has 0 decimal places, but our system stores in 4-decimal minor units
        // $100 USD = ¥15,000 JPY at rate 150
        $usdAmount = Money::toMinor(100.00);
        $rate = 150.0;
        $jpyAmount = (int) round($usdAmount * $rate);

        $this->assertEquals(Money::toMinor(15000.00), $jpyAmount);
    }

    // === Due date calculation logic ===

    public function test_due_date_with_zero_days_returns_base_date(): void
    {
        $baseDate = \Carbon\Carbon::parse('2026-03-01');
        $days = 0;
        $dueDate = $days > 0 ? $baseDate->copy()->addDays($days) : $baseDate->copy();

        $this->assertEquals('2026-03-01', $dueDate->toDateString());
    }

    public function test_due_date_with_30_days(): void
    {
        $baseDate = \Carbon\Carbon::parse('2026-03-01');
        $days = 30;
        $dueDate = $baseDate->copy()->addDays($days);

        $this->assertEquals('2026-03-31', $dueDate->toDateString());
    }

    public function test_due_date_with_90_days(): void
    {
        $baseDate = \Carbon\Carbon::parse('2026-01-01');
        $days = 90;
        $dueDate = $baseDate->copy()->addDays($days);

        $this->assertEquals('2026-04-01', $dueDate->toDateString());
    }

    // === Payment allocation scenarios ===

    public function test_payment_covers_multiple_schedule_items(): void
    {
        $paymentAmount = Money::toMinor(5000.00);
        $scheduleItems = [
            Money::toMinor(3000.00),
            Money::toMinor(3000.00),
            Money::toMinor(4000.00),
        ];

        $remaining = $paymentAmount;
        $allocations = [];

        foreach ($scheduleItems as $itemAmount) {
            if ($remaining <= 0) {
                break;
            }
            $allocation = min($remaining, $itemAmount);
            $allocations[] = $allocation;
            $remaining -= $allocation;
        }

        $this->assertEquals(Money::toMinor(3000.00), $allocations[0]);
        $this->assertEquals(Money::toMinor(2000.00), $allocations[1]);
        $this->assertCount(2, $allocations);
        $this->assertEquals(0, $remaining);
    }

    public function test_total_allocations_never_exceed_payment(): void
    {
        $paymentAmount = Money::toMinor(2500.00);
        $scheduleItems = [
            Money::toMinor(1000.00),
            Money::toMinor(1000.00),
            Money::toMinor(1000.00),
        ];

        $remaining = $paymentAmount;
        $totalAllocated = 0;

        foreach ($scheduleItems as $itemAmount) {
            if ($remaining <= 0) {
                break;
            }
            $allocation = min($remaining, $itemAmount);
            $totalAllocated += $allocation;
            $remaining -= $allocation;
        }

        $this->assertLessThanOrEqual($paymentAmount, $totalAllocated);
        $this->assertEquals(Money::toMinor(2500.00), $totalAllocated);
    }
}
