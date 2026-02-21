<?php

namespace Database\Seeders;

use App\Domain\Settings\Enums\CalculationBase;
use App\Domain\Settings\Models\PaymentTerm;
use Illuminate\Database\Seeder;

class PaymentTermSeeder extends Seeder
{
    public function run(): void
    {
        $term1 = PaymentTerm::updateOrCreate(
            ['name' => '100% in Advance'],
            [
                'description' => 'Full payment before production starts.',
                'is_default' => true,
                'is_active' => true,
            ]
        );

        $term1->stages()->delete();
        $term1->stages()->create([
            'percentage' => 100,
            'days' => 0,
            'calculation_base' => CalculationBase::ORDER_DATE,
            'sort_order' => 1,
        ]);

        $term2 = PaymentTerm::updateOrCreate(
            ['name' => '30/70'],
            [
                'description' => '30% deposit on order date, 70% balance before shipment.',
                'is_default' => false,
                'is_active' => true,
            ]
        );

        $term2->stages()->delete();
        $term2->stages()->createMany([
            [
                'percentage' => 30,
                'days' => 0,
                'calculation_base' => CalculationBase::ORDER_DATE,
                'sort_order' => 1,
            ],
            [
                'percentage' => 70,
                'days' => 0,
                'calculation_base' => CalculationBase::BEFORE_SHIPMENT,
                'sort_order' => 2,
            ],
        ]);
    }
}
