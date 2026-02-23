<?php

namespace Database\Factories;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    protected static array $productsByCategory = [
        'LED Lighting' => [
            ['name' => 'LED Panel Light 600x600mm 48W', 'hs_code' => '9405.42', 'brand' => 'BrightStar'],
            ['name' => 'LED Tube T8 1200mm 18W', 'hs_code' => '9405.42', 'brand' => 'BrightStar'],
            ['name' => 'LED Floodlight 100W IP65', 'hs_code' => '9405.42', 'brand' => 'TopBright'],
            ['name' => 'LED Street Light 150W', 'hs_code' => '9405.42', 'brand' => 'TopBright'],
            ['name' => 'LED Bulb A60 9W E27', 'hs_code' => '9405.42', 'brand' => 'BrightStar'],
        ],
        'Solar Panels' => [
            ['name' => 'Monocrystalline Solar Panel 550W', 'hs_code' => '8541.40', 'brand' => 'Sunlight'],
            ['name' => 'Polycrystalline Solar Panel 330W', 'hs_code' => '8541.40', 'brand' => 'Sunlight'],
            ['name' => 'Solar Inverter 5kW Hybrid', 'hs_code' => '8504.40', 'brand' => 'Sunlight'],
        ],
        'Batteries' => [
            ['name' => 'Lithium Battery Pack 48V 100Ah', 'hs_code' => '8507.60', 'brand' => 'Jianeng'],
            ['name' => 'LiFePO4 Battery 12V 200Ah', 'hs_code' => '8507.60', 'brand' => 'Jianeng'],
        ],
        'Cables & Connectors' => [
            ['name' => 'Solar Cable 6mm² PV1-F', 'hs_code' => '8544.49', 'brand' => 'Meida'],
            ['name' => 'MC4 Connector Pair IP67', 'hs_code' => '8536.90', 'brand' => 'Meida'],
        ],
        'Office Furniture' => [
            ['name' => 'Executive Office Desk 180cm', 'hs_code' => '9403.30', 'brand' => 'Haiyu'],
            ['name' => 'Ergonomic Mesh Office Chair', 'hs_code' => '9401.30', 'brand' => 'Haiyu'],
            ['name' => 'Filing Cabinet 4-Drawer Steel', 'hs_code' => '9403.20', 'brand' => 'Haiyu'],
        ],
        'Home Furniture' => [
            ['name' => 'Solid Wood Dining Table 6-Seater', 'hs_code' => '9403.60', 'brand' => 'Haiyu'],
            ['name' => 'Fabric Sofa 3-Seater L-Shape', 'hs_code' => '9401.61', 'brand' => 'Haiyu'],
        ],
        'Outdoor Furniture' => [
            ['name' => 'Rattan Garden Set 4-Piece', 'hs_code' => '9401.52', 'brand' => 'Haiyu'],
            ['name' => 'Aluminum Folding Table Outdoor', 'hs_code' => '9403.20', 'brand' => 'Haiyu'],
        ],
        'Fabrics' => [
            ['name' => 'Polyester Fabric 150cm 190T', 'hs_code' => '5407.61', 'brand' => 'Yongxin'],
            ['name' => 'Cotton Twill Fabric 280gsm', 'hs_code' => '5209.32', 'brand' => 'Yongxin'],
        ],
        'Garments' => [
            ['name' => 'Men Polo Shirt Cotton Pique', 'hs_code' => '6105.10', 'brand' => 'Yongxin'],
            ['name' => 'Women Yoga Pants Nylon/Spandex', 'hs_code' => '6104.63', 'brand' => 'Yongxin'],
        ],
        'Home Textiles' => [
            ['name' => 'Microfiber Bed Sheet Set Queen', 'hs_code' => '6302.32', 'brand' => 'Yongxin'],
            ['name' => 'Cotton Bath Towel 70x140cm 500gsm', 'hs_code' => '6302.60', 'brand' => 'Yongxin'],
        ],
        'Fasteners' => [
            ['name' => 'Hex Bolt M10x50 Stainless Steel', 'hs_code' => '7318.15', 'brand' => 'Deli'],
            ['name' => 'Self-Drilling Screw #12x25mm', 'hs_code' => '7318.14', 'brand' => 'Deli'],
        ],
        'Tools' => [
            ['name' => 'Cordless Drill 20V Li-Ion', 'hs_code' => '8467.21', 'brand' => 'Deli'],
            ['name' => 'Hand Tool Set 120-Piece', 'hs_code' => '8206.00', 'brand' => 'Deli'],
        ],
        'Plumbing' => [
            ['name' => 'PPR Pipe 25mm PN20', 'hs_code' => '3917.40', 'brand' => 'Deli'],
            ['name' => 'Brass Ball Valve 1/2"', 'hs_code' => '8481.80', 'brand' => 'Deli'],
        ],
        'Boxes & Cartons' => [
            ['name' => 'Corrugated Box 5-Layer 60x40x40cm', 'hs_code' => '4819.10', 'brand' => 'Greenpack'],
            ['name' => 'Custom Printed Gift Box', 'hs_code' => '4819.20', 'brand' => 'Greenpack'],
        ],
        'Bags & Pouches' => [
            ['name' => 'PE Zip Lock Bag 20x30cm', 'hs_code' => '3923.21', 'brand' => 'Greenpack'],
            ['name' => 'Non-Woven Shopping Bag Custom', 'hs_code' => '6305.33', 'brand' => 'Greenpack'],
        ],
        'Labels & Stickers' => [
            ['name' => 'Thermal Label Roll 100x150mm', 'hs_code' => '4821.10', 'brand' => 'Greenpack'],
            ['name' => 'Holographic Security Sticker', 'hs_code' => '4821.90', 'brand' => 'Greenpack'],
        ],
    ];

    public function definition(): array
    {
        return [
            'name' => 'Generic Product',
            'description' => fake()->paragraph(2),
            'status' => ProductStatus::ACTIVE,
            'category_id' => null,
            'hs_code' => fake()->numerify('####.##'),
            'origin_country' => 'CN',
            'brand' => fake()->company(),
            'model_number' => strtoupper(fake()->bothify('??-####')),
            'moq' => fake()->randomElement([100, 200, 500, 1000, 2000, 5000]),
            'moq_unit' => 'pcs',
            'lead_time_days' => fake()->randomElement([15, 20, 25, 30, 35, 45, 60]),
        ];
    }

    public function forCategory(string $categoryName): static
    {
        $products = static::$productsByCategory[$categoryName] ?? [];

        if (empty($products)) {
            return $this;
        }

        $product = fake()->randomElement($products);

        return $this->state(function () use ($categoryName, $product) {
            $category = Category::where('name', $categoryName)->first();

            return [
                'name' => $product['name'],
                'category_id' => $category?->id,
                'hs_code' => $product['hs_code'],
                'brand' => $product['brand'],
                'origin_country' => 'CN',
                'model_number' => strtoupper(fake()->bothify('??-####')),
                'moq' => fake()->randomElement([100, 200, 500, 1000, 2000]),
                'lead_time_days' => fake()->randomElement([15, 25, 30, 45]),
            ];
        });
    }

    public static function getProductsForCategory(string $categoryName): array
    {
        return static::$productsByCategory[$categoryName] ?? [];
    }

    public static function getAllCategoryNames(): array
    {
        return array_keys(static::$productsByCategory);
    }
}
