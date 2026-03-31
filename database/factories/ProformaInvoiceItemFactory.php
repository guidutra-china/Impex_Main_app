<?php

namespace Database\Factories;

use App\Domain\Catalog\Models\Product;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProformaInvoiceItemFactory extends Factory
{
    protected $model = ProformaInvoiceItem::class;

    public function definition(): array
    {
        return [
            'proforma_invoice_id' => ProformaInvoice::factory(),
            'product_id'          => Product::factory(),
            'description'         => $this->faker->words(3, true),
            'quantity'            => $this->faker->numberBetween(100, 500),
            'unit'                => 'pcs',
            'unit_price'          => $this->faker->randomFloat(2, 50, 500),
            'unit_cost'           => $this->faker->randomFloat(2, 20, 200),
            'sort_order'          => 0,
        ];
    }
}
