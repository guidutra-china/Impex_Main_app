<?php

namespace Database\Factories;

use App\Domain\Planning\Enums\ComponentStatus;
use App\Domain\Planning\Models\ProductionSchedule;
use App\Domain\Planning\Models\ProductionScheduleComponent;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductionScheduleComponentFactory extends Factory
{
    protected $model = ProductionScheduleComponent::class;

    public function definition(): array
    {
        return [
            'production_schedule_id'   => ProductionSchedule::factory(),
            'proforma_invoice_item_id' => ProformaInvoiceItem::factory(),
            'component_name'           => null,
            'status'                   => ComponentStatus::AtSupplier,
            'supplier_name'            => $this->faker->company(),
            'eta'                      => $this->faker->dateTimeBetween('+1 week', '+6 weeks'),
            'notes'                    => null,
            'updated_by'               => null,
        ];
    }

    public function atFactory(): static
    {
        return $this->state(['status' => ComponentStatus::AtFactory, 'eta' => null]);
    }

    public function inTransit(): static
    {
        return $this->state(['status' => ComponentStatus::InTransit]);
    }

    public function named(string $name): static
    {
        return $this->state(['component_name' => $name]);
    }
}
