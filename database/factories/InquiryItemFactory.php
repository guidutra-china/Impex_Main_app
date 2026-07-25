<?php

namespace Database\Factories;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Inquiries\Models\InquiryItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InquiryItem>
 */
class InquiryItemFactory extends Factory
{
    protected $model = InquiryItem::class;

    public function definition(): array
    {
        return [
            'inquiry_id' => Inquiry::factory(),
            'product_id' => Product::factory(),
            'description' => $this->faker->words(3, true),
            'quantity' => 1,
            'unit' => 'pcs',
            'target_price' => null,
            'specifications' => null,
            'notes' => null,
            'sort_order' => 0,
        ];
    }
}
