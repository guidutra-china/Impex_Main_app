<?php

namespace Database\Seeders;

use App\Domain\Catalog\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $tree = [
            [
                'name' => 'Electronics',
                'sku_prefix' => 'ELE',
                'sort_order' => 10,
                'children' => [
                    ['name' => 'LED Lighting', 'sku_prefix' => 'LED', 'sort_order' => 1],
                    ['name' => 'Solar Panels', 'sku_prefix' => 'SOL', 'sort_order' => 2],
                    ['name' => 'Batteries', 'sku_prefix' => 'BAT', 'sort_order' => 3],
                    ['name' => 'Cables & Connectors', 'sku_prefix' => 'CAB', 'sort_order' => 4],
                ],
            ],
            [
                'name' => 'Furniture',
                'sku_prefix' => 'FUR',
                'sort_order' => 20,
                'children' => [
                    ['name' => 'Office Furniture', 'sku_prefix' => 'OFC', 'sort_order' => 1],
                    ['name' => 'Home Furniture', 'sku_prefix' => 'HMF', 'sort_order' => 2],
                    ['name' => 'Outdoor Furniture', 'sku_prefix' => 'OUT', 'sort_order' => 3],
                ],
            ],
            [
                'name' => 'Textiles',
                'sku_prefix' => 'TEX',
                'sort_order' => 30,
                'children' => [
                    ['name' => 'Fabrics', 'sku_prefix' => 'FAB', 'sort_order' => 1],
                    ['name' => 'Garments', 'sku_prefix' => 'GAR', 'sort_order' => 2],
                    ['name' => 'Home Textiles', 'sku_prefix' => 'HTX', 'sort_order' => 3],
                ],
            ],
            [
                'name' => 'Hardware',
                'sku_prefix' => 'HRD',
                'sort_order' => 40,
                'children' => [
                    ['name' => 'Fasteners', 'sku_prefix' => 'FST', 'sort_order' => 1],
                    ['name' => 'Tools', 'sku_prefix' => 'TLS', 'sort_order' => 2],
                    ['name' => 'Plumbing', 'sku_prefix' => 'PLB', 'sort_order' => 3],
                ],
            ],
            [
                'name' => 'Packaging Materials',
                'sku_prefix' => 'PKG',
                'sort_order' => 50,
                'children' => [
                    ['name' => 'Boxes & Cartons', 'sku_prefix' => 'BOX', 'sort_order' => 1],
                    ['name' => 'Bags & Pouches', 'sku_prefix' => 'BAG', 'sort_order' => 2],
                    ['name' => 'Labels & Stickers', 'sku_prefix' => 'LBL', 'sort_order' => 3],
                ],
            ],
        ];

        foreach ($tree as $rootData) {
            $children = $rootData['children'] ?? [];
            unset($rootData['children']);

            $root = Category::updateOrCreate(
                ['slug' => Str::slug($rootData['name'])],
                [
                    'name' => $rootData['name'],
                    'sku_prefix' => $rootData['sku_prefix'],
                    'sort_order' => $rootData['sort_order'],
                    'is_active' => true,
                    'parent_id' => null,
                ]
            );

            foreach ($children as $childData) {
                Category::updateOrCreate(
                    ['slug' => Str::slug($childData['name'])],
                    [
                        'name' => $childData['name'],
                        'sku_prefix' => $childData['sku_prefix'],
                        'sort_order' => $childData['sort_order'],
                        'is_active' => true,
                        'parent_id' => $root->id,
                    ]
                );
            }
        }
    }
}
