<?php

namespace Database\Seeders;

use App\Domain\Settings\Enums\PaymentMethodType;
use App\Domain\Settings\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        $methods = [
            [
                'name' => 'TT Transfer',
                'type' => PaymentMethodType::BANK_TRANSFER,
                'is_active' => true,
            ],
            [
                'name' => 'Deposit',
                'type' => PaymentMethodType::BANK_TRANSFER,
                'is_active' => true,
            ],
            [
                'name' => 'Cash',
                'type' => PaymentMethodType::CASH,
                'is_active' => true,
            ],
        ];

        foreach ($methods as $method) {
            PaymentMethod::updateOrCreate(
                ['name' => $method['name']],
                $method
            );
        }
    }
}
