<?php

namespace Database\Seeders;

use App\Domain\Catalog\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Electronics', 'sku_prefix' => 'ELE', 'sort_order' => 10],
            ['name' => 'Furniture', 'sku_prefix' => 'FUR', 'sort_order' => 20],
            ['name' => 'Textiles', 'sku_prefix' => 'TEX', 'sort_order' => 30],
            ['name' => 'Hardware', 'sku_prefix' => 'HRD', 'sort_order' => 40],
            ['name' => 'Packaging Materials', 'sku_prefix' => 'PKG', 'sort_order' => 50],
        ];

        foreach ($categories as $category) {
            Category::updateOrCreate(
                ['slug' => Str::slug($category['name'])],
                [
                    'name' => $category['name'],
                    'sku_prefix' => $category['sku_prefix'],
                    'sort_order' => $category['sort_order'],
                    'is_active' => true,
                ]
            );
        }
    }
}
