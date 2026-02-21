<?php

namespace Database\Seeders;

use App\Domain\Settings\Models\ContainerType;
use Illuminate\Database\Seeder;

class ContainerTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            [
                'name' => "20' General Purpose",
                'code' => '20GP',
                'description' => 'Standard 20-foot dry container.',
                'length_ft' => 19.35,
                'width_ft' => 7.71,
                'height_ft' => 7.84,
                'max_weight_kg' => 28000,
                'cubic_capacity_cbm' => 33.2,
                'is_active' => true,
            ],
            [
                'name' => "40' General Purpose",
                'code' => '40GP',
                'description' => 'Standard 40-foot dry container.',
                'length_ft' => 39.46,
                'width_ft' => 7.71,
                'height_ft' => 7.84,
                'max_weight_kg' => 26500,
                'cubic_capacity_cbm' => 67.7,
                'is_active' => true,
            ],
            [
                'name' => "40' High Cube",
                'code' => '40HC',
                'description' => 'High cube 40-foot container with extra height.',
                'length_ft' => 39.46,
                'width_ft' => 7.71,
                'height_ft' => 8.84,
                'max_weight_kg' => 26500,
                'cubic_capacity_cbm' => 76.3,
                'is_active' => true,
            ],
        ];

        foreach ($types as $type) {
            ContainerType::updateOrCreate(
                ['code' => $type['code']],
                $type
            );
        }
    }
}
