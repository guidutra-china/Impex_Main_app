<?php

namespace Database\Seeders;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\CategoryAttribute;
use Illuminate\Database\Seeder;

class CategoryAttributeSeeder extends Seeder
{
    public function run(): void
    {
        // --- Electronics (root) - inherited by all electronics subcategories ---
        $electronics = Category::where('name', 'Electronics')->whereNull('parent_id')->first();

        if ($electronics) {
            $this->createAttributes($electronics, [
                ['name' => 'Voltage', 'unit' => 'V', 'type' => 'text', 'default_value' => '220-240', 'is_required' => true, 'sort_order' => 1],
                ['name' => 'Certification', 'unit' => null, 'type' => 'select', 'options' => ['CE', 'UL', 'FCC', 'RoHS', 'SAA', 'TUV'], 'is_required' => false, 'sort_order' => 2],
                ['name' => 'IP Rating', 'unit' => null, 'type' => 'select', 'options' => ['IP20', 'IP44', 'IP54', 'IP65', 'IP66', 'IP67', 'IP68'], 'is_required' => false, 'sort_order' => 3],
            ]);
        }

        // --- LED Lighting (child of Electronics) ---
        $ledLighting = Category::where('name', 'LED Lighting')->first();

        if ($ledLighting) {
            $this->createAttributes($ledLighting, [
                ['name' => 'Watts', 'unit' => 'W', 'type' => 'number', 'default_value' => null, 'is_required' => true, 'sort_order' => 1],
                ['name' => 'CCT', 'unit' => 'K', 'type' => 'select', 'options' => ['3000K', '4000K', '5000K', '6500K'], 'is_required' => true, 'sort_order' => 2],
                ['name' => 'LED Chip', 'unit' => null, 'type' => 'select', 'options' => ['SMD2835', 'SMD5050', 'SMD3030', 'COB', 'Osram', 'Cree', 'Bridgelux'], 'is_required' => false, 'sort_order' => 3],
                ['name' => 'Driver', 'unit' => null, 'type' => 'text', 'default_value' => null, 'is_required' => false, 'sort_order' => 4],
                ['name' => 'Lumens', 'unit' => 'lm', 'type' => 'number', 'default_value' => null, 'is_required' => true, 'sort_order' => 5],
                ['name' => 'Lumens/Watt', 'unit' => 'lm/W', 'type' => 'number', 'default_value' => null, 'is_required' => false, 'sort_order' => 6],
                ['name' => 'Beam Angle', 'unit' => '°', 'type' => 'select', 'options' => ['15°', '30°', '45°', '60°', '90°', '120°'], 'is_required' => false, 'sort_order' => 7],
                ['name' => 'CRI', 'unit' => null, 'type' => 'select', 'options' => ['>70', '>80', '>90', '>95'], 'is_required' => false, 'sort_order' => 8],
                ['name' => 'Dimmable', 'unit' => null, 'type' => 'boolean', 'default_value' => null, 'is_required' => false, 'sort_order' => 9],
                ['name' => 'Lifespan', 'unit' => 'hours', 'type' => 'number', 'default_value' => '50000', 'is_required' => false, 'sort_order' => 10],
            ]);
        }

        // --- Solar Panels (child of Electronics) ---
        $solarPanels = Category::where('name', 'Solar Panels')->first();

        if ($solarPanels) {
            $this->createAttributes($solarPanels, [
                ['name' => 'Power Output', 'unit' => 'W', 'type' => 'number', 'default_value' => null, 'is_required' => true, 'sort_order' => 1],
                ['name' => 'Panel Type', 'unit' => null, 'type' => 'select', 'options' => ['Monocrystalline', 'Polycrystalline', 'Thin Film'], 'is_required' => true, 'sort_order' => 2],
                ['name' => 'Efficiency', 'unit' => '%', 'type' => 'number', 'default_value' => null, 'is_required' => false, 'sort_order' => 3],
            ]);
        }

        // --- Furniture (root) ---
        $furniture = Category::where('name', 'Furniture')->whereNull('parent_id')->first();

        if ($furniture) {
            $this->createAttributes($furniture, [
                ['name' => 'Material', 'unit' => null, 'type' => 'text', 'default_value' => null, 'is_required' => true, 'sort_order' => 1],
                ['name' => 'Color', 'unit' => null, 'type' => 'text', 'default_value' => null, 'is_required' => true, 'sort_order' => 2],
                ['name' => 'Finish', 'unit' => null, 'type' => 'select', 'options' => ['Matte', 'Glossy', 'Satin', 'Textured', 'Natural'], 'is_required' => false, 'sort_order' => 3],
                ['name' => 'Assembly Required', 'unit' => null, 'type' => 'boolean', 'default_value' => null, 'is_required' => false, 'sort_order' => 4],
            ]);
        }
    }

    private function createAttributes(Category $category, array $attributes): void
    {
        foreach ($attributes as $attr) {
            CategoryAttribute::updateOrCreate(
                [
                    'category_id' => $category->id,
                    'name' => $attr['name'],
                ],
                [
                    'default_value' => $attr['default_value'] ?? null,
                    'unit' => $attr['unit'],
                    'type' => $attr['type'],
                    'options' => $attr['options'] ?? null,
                    'is_required' => $attr['is_required'],
                    'sort_order' => $attr['sort_order'],
                ]
            );
        }
    }
}
