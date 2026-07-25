<?php

namespace Database\Factories;

use App\Domain\Catalog\Models\Product;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use App\Domain\SupplierQuotations\Models\SupplierQuotationItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierQuotationItem>
 */
class SupplierQuotationItemFactory extends Factory
{
    protected $model = SupplierQuotationItem::class;

    public function definition(): array
    {
        return [
            'supplier_quotation_id' => SupplierQuotation::factory(),
            'inquiry_item_id' => null,
            'product_id' => Product::factory(),
            'description' => $this->faker->words(3, true),
            'quantity' => 1,
            'unit' => 'pcs',
            'unit_cost' => 1000,
            'moq' => null,
            'lead_time_days' => null,
            'specifications' => null,
            'notes' => null,
            'sort_order' => 0,
        ];
    }
}
