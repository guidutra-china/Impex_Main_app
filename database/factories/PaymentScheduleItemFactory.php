<?php

namespace Database\Factories;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\Settings\Enums\CalculationBase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentScheduleItem>
 */
class PaymentScheduleItemFactory extends Factory
{
    protected $model = PaymentScheduleItem::class;

    public function definition(): array
    {
        return [
            // Default payable is a ProformaInvoice; tests usually override both.
            'payable_type' => ProformaInvoice::class,
            'payable_id' => ProformaInvoice::factory(),
            'payment_term_stage_id' => null,
            'shipment_id' => null,
            'label' => $this->faker->randomElement(['30% — Order Date', '70% — Shipment Date']),
            'percentage' => $this->faker->randomElement([30, 50, 70, 100]),
            'amount' => $this->faker->numberBetween(100_00, 500_00),
            'currency_code' => 'USD',
            'due_condition' => CalculationBase::SHIPMENT_DATE,
            'due_date' => now()->addDays(30),
            'status' => PaymentScheduleStatus::PENDING,
            'is_blocking' => false,
            'is_credit' => false,
            'sort_order' => 0,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => ['status' => PaymentScheduleStatus::PAID]);
    }

    public function overdue(): static
    {
        return $this->state(fn () => [
            'status' => PaymentScheduleStatus::PENDING,
            'due_date' => now()->subDays(5),
        ]);
    }

    public function credit(): static
    {
        return $this->state(fn () => ['is_credit' => true]);
    }
}
