<?php

namespace Database\Factories;

use App\Domain\CRM\Models\Company;
use App\Domain\Planning\Enums\ShipmentPlanStatus;
use App\Domain\Planning\Models\ShipmentPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShipmentPlan>
 */
class ShipmentPlanFactory extends Factory
{
    protected $model = ShipmentPlan::class;

    public function definition(): array
    {
        return [
            'supplier_company_id' => Company::factory(),
            'shipment_id' => null,
            'reference' => 'SP-'.$this->faker->unique()->numerify('####'),
            'status' => ShipmentPlanStatus::DRAFT->value,
            'planned_shipment_date' => null,
            'planned_eta' => null,
            'currency_code' => 'USD',
            'container_constraints' => null,
            'notes' => null,
            'created_by' => null,
        ];
    }
}
