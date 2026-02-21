<?php

namespace Database\Seeders;

use App\Domain\Catalog\Models\Tag;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TagSeeder extends Seeder
{
    public function run(): void
    {
        $tags = [
            ['name' => 'New Arrival', 'color' => '#10b981'],
            ['name' => 'Best Seller', 'color' => '#f59e0b'],
            ['name' => 'Clearance', 'color' => '#ef4444'],
            ['name' => 'Eco-Friendly', 'color' => '#22c55e'],
            ['name' => 'Premium', 'color' => '#8b5cf6'],
        ];

        foreach ($tags as $tag) {
            Tag::updateOrCreate(
                ['slug' => Str::slug($tag['name'])],
                [
                    'name' => $tag['name'],
                    'color' => $tag['color'],
                ]
            );
        }
    }
}
