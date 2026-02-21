<?php

namespace Database\Seeders;

use App\Domain\Settings\Models\Currency;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        $currencies = [
            [
                'code' => 'USD',
                'name' => 'US Dollar',
                'name_plural' => 'US Dollars',
                'symbol' => '$',
                'decimal_places' => 2,
                'is_base' => true,
                'is_active' => true,
            ],
            [
                'code' => 'CNY',
                'name' => 'Chinese Yuan',
                'name_plural' => 'Chinese Yuan',
                'symbol' => '¥',
                'decimal_places' => 2,
                'is_base' => false,
                'is_active' => true,
            ],
            [
                'code' => 'EUR',
                'name' => 'Euro',
                'name_plural' => 'Euros',
                'symbol' => '€',
                'decimal_places' => 2,
                'is_base' => false,
                'is_active' => true,
            ],
            [
                'code' => 'BRL',
                'name' => 'Brazilian Real',
                'name_plural' => 'Brazilian Reais',
                'symbol' => 'R$',
                'decimal_places' => 2,
                'is_base' => false,
                'is_active' => true,
            ],
        ];

        foreach ($currencies as $currency) {
            Currency::updateOrCreate(
                ['code' => $currency['code']],
                $currency
            );
        }
    }
}
