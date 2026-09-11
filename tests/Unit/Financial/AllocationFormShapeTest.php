<?php

namespace Tests\Unit\Financial;

use App\Domain\Financial\Support\AllocationFormShape;
use PHPUnit\Framework\TestCase;

class AllocationFormShapeTest extends TestCase
{
    public function test_flatten_turns_nested_credits_into_one_row_per_credit_and_item(): void
    {
        $rows = AllocationFormShape::flattenCredits([
            'u1' => ['payment_schedule_item_id' => 10, 'allocated_amount' => '100.00', 'credits' => [
                'c1' => ['credit_schedule_item_id' => 7, 'credit_amount' => '20.50'],
                'c2' => ['credit_schedule_item_id' => 8, 'credit_amount' => '0'],      // ignorado
                'c3' => ['credit_schedule_item_id' => null, 'credit_amount' => '5'],  // ignorado
            ]],
            'u2' => ['payment_schedule_item_id' => null, 'credits' => [['credit_schedule_item_id' => 9, 'credit_amount' => '1']]], // sem parcela
            'u3' => ['payment_schedule_item_id' => 11],
        ]);

        $this->assertSame([
            ['credit_schedule_item_id' => 7, 'payment_schedule_item_id' => 10, 'credit_amount' => 20.5],
        ], $rows);
    }

    public function test_nest_attaches_credits_to_their_item_and_creates_a_cashless_row_when_missing(): void
    {
        $nested = AllocationFormShape::nestCredits(
            [['payment_schedule_item_id' => 10, 'allocated_amount' => 80.0]],
            [
                ['credit_schedule_item_id' => 7, 'payment_schedule_item_id' => 10, 'credit_amount' => 20.0],
                ['credit_schedule_item_id' => 8, 'payment_schedule_item_id' => 12, 'credit_amount' => 5.0, 'document_currency_code' => 'USD'],
            ],
        );

        $this->assertCount(2, $nested);
        $this->assertSame(7, $nested[0]['credits'][0]['credit_schedule_item_id']);
        $this->assertSame(80.0, $nested[0]['allocated_amount']);

        // Crédito sozinho vira linha com dinheiro zero.
        $this->assertSame(12, $nested[1]['payment_schedule_item_id']);
        $this->assertSame('0.00', $nested[1]['allocated_amount']);
        $this->assertSame('USD', $nested[1]['document_currency_code']);
        $this->assertSame(8, $nested[1]['credits'][0]['credit_schedule_item_id']);
    }

    public function test_round_trip_preserves_the_flat_rows(): void
    {
        $flat = [
            ['credit_schedule_item_id' => 7, 'payment_schedule_item_id' => 10, 'credit_amount' => 20.0],
            ['credit_schedule_item_id' => 9, 'payment_schedule_item_id' => 10, 'credit_amount' => 3.25],
        ];

        $again = AllocationFormShape::flattenCredits(AllocationFormShape::nestCredits([['payment_schedule_item_id' => 10]], $flat));

        $this->assertSame($flat, $again);
    }

    public function test_credit_totals_group_by_document_currency(): void
    {
        $totals = AllocationFormShape::creditTotalsByCurrency([
            ['document_currency_code' => 'USD', 'credits' => [['credit_amount' => '10']]],
            ['document_currency_code' => '', 'credits' => [['credit_amount' => '5']]],
            ['document_currency_code' => 'CNY', 'credits' => [['credit_amount' => '70']]],
        ], 'USD');

        $this->assertSame(['USD' => 15.0, 'CNY' => 70.0], $totals);
    }
}
