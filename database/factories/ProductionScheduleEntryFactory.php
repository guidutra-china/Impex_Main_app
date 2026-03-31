<?php

namespace Database\Factories;

use App\Domain\Planning\Models\ProductionSchedule;
use App\Domain\Planning\Models\ProductionScheduleEntry;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductionScheduleEntryFactory extends Factory
{
    protected $model = ProductionScheduleEntry::class;

    public function definition(): array
    {
        return [
            'production_schedule_id'   => ProductionSchedule::factory(),
            'proforma_invoice_item_id' => ProformaInvoiceItem::factory(),
            'purchase_order_item_id'   => null,
            'production_date'          => $this->faker->dateTimeBetween('+1 week', '+8 weeks'),
            'quantity'                 => $this->faker->numberBetween(50, 300),
            'actual_quantity'          => null,
        ];
    }
}
