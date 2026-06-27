<?php

namespace Database\Factories;

use App\Domain\CRM\Models\Company;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shipment>
 */
class ShipmentFactory extends Factory
{
    protected $model = Shipment::class;

    public function definition(): array
    {
        return [
            'reference' => 'SH-'.$this->faker->unique()->numerify('####'),
            'company_id' => Company::factory(),
            'status' => ShipmentStatus::DRAFT->value,
            'currency_code' => 'USD',
            'etd' => now()->addDays(10)->toDateString(),
            'eta' => now()->addDays(40)->toDateString(),
        ];
    }
}
