<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // Auth
            AdminUserSeeder::class,

            // Settings
            CurrencySeeder::class,
            PaymentMethodSeeder::class,
            PaymentTermSeeder::class,
            ContainerTypeSeeder::class,

            // Catalog
            CategorySeeder::class,
            TagSeeder::class,
        ]);
    }
}
