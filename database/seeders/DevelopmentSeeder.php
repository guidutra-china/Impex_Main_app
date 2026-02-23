<?php

namespace Database\Seeders;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPackaging;
use App\Domain\Catalog\Models\ProductSpecification;
use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Enums\ContactFunction;
use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Models\CompanyRoleAssignment;
use App\Domain\CRM\Models\Contact;
use Illuminate\Database\Seeder;

class DevelopmentSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Seeding development data...');

        $suppliers = $this->createSuppliers();
        $clients = $this->createClients();
        $products = $this->createProducts();

        $this->linkSuppliersToProducts($suppliers, $products);
        $this->linkClientsToProducts($clients, $products);
        $this->linkCompaniesToCategories($suppliers, $clients);

        $this->command->info('Development data seeded successfully.');
        $this->command->info("  - {$suppliers->count()} suppliers with contacts");
        $this->command->info("  - {$clients->count()} clients with contacts");
        $this->command->info("  - {$products->count()} products with specs & packaging");
    }

    protected function createSuppliers(): \Illuminate\Support\Collection
    {
        $suppliers = collect();

        for ($i = 0; $i < 8; $i++) {
            $company = Company::factory()->supplier()->create();

            CompanyRoleAssignment::create([
                'company_id' => $company->id,
                'role' => CompanyRole::SUPPLIER,
            ]);

            if ($i < 3) {
                CompanyRoleAssignment::create([
                    'company_id' => $company->id,
                    'role' => CompanyRole::MANUFACTURER,
                ]);
            }

            Contact::factory()->chinese()->primary()->create([
                'company_id' => $company->id,
                'function' => ContactFunction::SALES,
                'position' => $this->pick(['Sales Manager', 'Export Manager', 'Business Manager']),
            ]);

            Contact::factory()->chinese()->create([
                'company_id' => $company->id,
                'function' => ContactFunction::LOGISTICS,
                'position' => 'Shipping Coordinator',
            ]);

            if (rand(1, 100) <= 60) {
                Contact::factory()->chinese()->create([
                    'company_id' => $company->id,
                    'function' => ContactFunction::QUALITY,
                    'position' => 'QC Manager',
                ]);
            }

            $suppliers->push($company);
        }

        return $suppliers;
    }

    protected function createClients(): \Illuminate\Support\Collection
    {
        $clients = collect();

        for ($i = 0; $i < 6; $i++) {
            $company = Company::factory()->client()->create();

            CompanyRoleAssignment::create([
                'company_id' => $company->id,
                'role' => CompanyRole::CLIENT,
            ]);

            Contact::factory()->brazilian()->primary()->create([
                'company_id' => $company->id,
                'function' => ContactFunction::PURCHASING,
                'position' => $this->pick(['Diretor de Compras', 'Gerente de Importação', 'Comprador']),
            ]);

            Contact::factory()->brazilian()->create([
                'company_id' => $company->id,
                'function' => ContactFunction::FINANCE,
                'position' => $this->pick(['Gerente Financeiro', 'Analista Financeiro']),
            ]);

            if (rand(1, 100) <= 50) {
                Contact::factory()->brazilian()->create([
                    'company_id' => $company->id,
                    'function' => ContactFunction::LOGISTICS,
                    'position' => 'Coordenador de Logística',
                ]);
            }

            $clients->push($company);
        }

        return $clients;
    }

    protected function createProducts(): \Illuminate\Support\Collection
    {
        $products = collect();
        $subcategories = Category::whereNotNull('parent_id')->where('is_active', true)->get();

        foreach ($subcategories as $subcategory) {
            $productDefs = \Database\Factories\ProductFactory::getProductsForCategory($subcategory->name);

            foreach ($productDefs as $productDef) {
                $product = Product::create([
                    'name' => $productDef['name'],
                    'description' => $this->generateProductDescription($productDef['name']),
                    'status' => 'active',
                    'category_id' => $subcategory->id,
                    'hs_code' => $productDef['hs_code'],
                    'origin_country' => 'CN',
                    'brand' => $productDef['brand'],
                    'model_number' => strtoupper($this->randomCode('??-####')),
                    'moq' => $this->pick([100, 200, 500, 1000, 2000, 5000]),
                    'moq_unit' => 'pcs',
                    'lead_time_days' => $this->pick([15, 20, 25, 30, 45]),
                ]);

                $this->createProductSpecification($product);
                $this->createProductPackaging($product);

                $products->push($product);
            }
        }

        return $products;
    }

    protected function createProductSpecification(Product $product): void
    {
        ProductSpecification::create([
            'product_id' => $product->id,
            'net_weight' => $this->randomFloat(3, 0.05, 50),
            'gross_weight' => $this->randomFloat(3, 0.1, 55),
            'length' => $this->randomFloat(2, 5, 200),
            'width' => $this->randomFloat(2, 5, 150),
            'height' => $this->randomFloat(2, 1, 100),
            'material' => $this->pick([
                'ABS Plastic', 'Aluminum Alloy', 'Stainless Steel 304',
                'Polyester', 'Cotton', 'MDF Board', 'Tempered Glass',
                'Polycarbonate', 'Brass', 'PVC', 'Nylon',
            ]),
            'color' => $this->pick([
                'White', 'Black', 'Silver', 'Natural', 'Custom',
                'Blue', 'Red', 'Green', 'Gray', 'Multi-color',
            ]),
        ]);
    }

    protected function createProductPackaging(Product $product): void
    {
        $pcsPerCarton = $this->pick([1, 2, 4, 6, 10, 12, 20, 24, 50, 100]);
        $cartonL = $this->randomFloat(2, 20, 80);
        $cartonW = $this->randomFloat(2, 15, 60);
        $cartonH = $this->randomFloat(2, 10, 50);
        $cbm = round(($cartonL * $cartonW * $cartonH) / 1000000, 4);

        ProductPackaging::create([
            'product_id' => $product->id,
            'pcs_per_carton' => $pcsPerCarton,
            'carton_length' => $cartonL,
            'carton_width' => $cartonW,
            'carton_height' => $cartonH,
            'carton_weight' => $this->randomFloat(3, 1, 30),
            'carton_cbm' => $cbm,
            'cartons_per_20ft' => $cbm > 0 ? (int) floor(28 / $cbm) : null,
            'cartons_per_40ft' => $cbm > 0 ? (int) floor(58 / $cbm) : null,
            'cartons_per_40hq' => $cbm > 0 ? (int) floor(68 / $cbm) : null,
        ]);
    }

    protected function linkSuppliersToProducts(\Illuminate\Support\Collection $suppliers, \Illuminate\Support\Collection $products): void
    {
        $supplierCategoryMap = [
            0 => ['LED Lighting', 'Batteries'],
            1 => ['Office Furniture', 'Home Furniture', 'Outdoor Furniture'],
            2 => ['Fabrics', 'Garments', 'Home Textiles'],
            3 => ['Fasteners', 'Tools', 'Plumbing'],
            4 => ['Boxes & Cartons', 'Bags & Pouches', 'Labels & Stickers'],
            5 => ['Solar Panels'],
            6 => ['Cables & Connectors'],
            7 => ['LED Lighting'],
        ];

        foreach ($suppliers as $index => $supplier) {
            $categoryNames = $supplierCategoryMap[$index] ?? [];

            foreach ($categoryNames as $catName) {
                $categoryProducts = $products->filter(function ($p) use ($catName) {
                    $cat = Category::find($p->category_id);
                    return $cat && $cat->name === $catName;
                });

                foreach ($categoryProducts as $product) {
                    $basePrice = $this->pick([50, 120, 250, 500, 800, 1500, 3000, 5000, 10000, 25000]);

                    $supplier->products()->attach($product->id, [
                        'role' => 'supplier',
                        'external_code' => strtoupper($this->randomCode('??-#####')),
                        'external_name' => $product->name,
                        'unit_price' => $basePrice * 100,
                        'currency_code' => $this->pick(['USD', 'CNY']),
                        'incoterm' => $this->pick(['FOB', 'EXW', 'CIF']),
                        'lead_time_days' => $product->lead_time_days,
                        'moq' => $product->moq,
                        'is_preferred' => $index < 5,
                    ]);
                }
            }
        }
    }

    protected function linkClientsToProducts(\Illuminate\Support\Collection $clients, \Illuminate\Support\Collection $products): void
    {
        $clientInterests = [
            0 => ['LED Lighting', 'Solar Panels', 'Batteries', 'Cables & Connectors'],
            1 => ['Home Furniture', 'Office Furniture', 'Home Textiles'],
            2 => ['LED Lighting'],
            3 => ['Fasteners', 'Tools', 'Plumbing'],
            4 => ['Fabrics', 'Garments', 'Home Textiles'],
            5 => ['Solar Panels', 'Batteries', 'Cables & Connectors'],
        ];

        foreach ($clients as $index => $client) {
            $categoryNames = $clientInterests[$index] ?? [];

            foreach ($categoryNames as $catName) {
                $categoryProducts = $products->filter(function ($p) use ($catName) {
                    $cat = Category::find($p->category_id);
                    return $cat && $cat->name === $catName;
                });

                foreach ($categoryProducts as $product) {
                    $supplierPrice = $product->companies()
                        ->wherePivot('role', 'supplier')
                        ->first()?->pivot?->unit_price ?? 50000;

                    $markup = $this->randomFloat(2, 1.15, 1.45);
                    $clientPrice = (int) round($supplierPrice * $markup);

                    $client->products()->attach($product->id, [
                        'role' => 'client',
                        'external_code' => strtoupper($this->randomCode('CLI-#####')),
                        'external_name' => $product->name,
                        'unit_price' => $clientPrice,
                        'currency_code' => 'USD',
                        'incoterm' => $this->pick(['CIF', 'CFR', 'FOB']),
                        'lead_time_days' => ($product->lead_time_days ?? 30) + rand(10, 20),
                        'moq' => $product->moq,
                        'is_preferred' => false,
                    ]);
                }
            }
        }
    }

    protected function linkCompaniesToCategories(\Illuminate\Support\Collection $suppliers, \Illuminate\Support\Collection $clients): void
    {
        foreach ($suppliers as $supplier) {
            $categoryIds = $supplier->products()
                ->wherePivot('role', 'supplier')
                ->get()
                ->pluck('category_id')
                ->unique()
                ->filter();

            $parentIds = Category::whereIn('id', $categoryIds)->pluck('parent_id')->unique()->filter();
            $allCategoryIds = $categoryIds->merge($parentIds)->unique();

            foreach ($allCategoryIds as $catId) {
                $supplier->categories()->syncWithoutDetaching([$catId => ['notes' => null]]);
            }
        }

        foreach ($clients as $client) {
            $categoryIds = $client->products()
                ->wherePivot('role', 'client')
                ->get()
                ->pluck('category_id')
                ->unique()
                ->filter();

            $parentIds = Category::whereIn('id', $categoryIds)->pluck('parent_id')->unique()->filter();
            $allCategoryIds = $categoryIds->merge($parentIds)->unique();

            foreach ($allCategoryIds as $catId) {
                $client->categories()->syncWithoutDetaching([$catId => ['notes' => null]]);
            }
        }
    }

    protected function generateProductDescription(string $productName): string
    {
        $descriptions = [
            'High quality product manufactured in China with strict quality control standards.',
            'Premium grade product suitable for international markets. CE and RoHS certified.',
            'Cost-effective solution with reliable performance. OEM/ODM available.',
            'Industry-standard product with customization options. Bulk pricing available.',
            'Professional grade product designed for commercial applications.',
        ];

        return $productName . '. ' . $this->pick($descriptions);
    }

    // --- Simple helpers to avoid Faker dependency in Seeder ---

    protected function pick(array $items): mixed
    {
        return $items[array_rand($items)];
    }

    protected function randomFloat(int $decimals, float $min, float $max): float
    {
        return round($min + mt_rand() / mt_getrandmax() * ($max - $min), $decimals);
    }

    protected function randomCode(string $pattern): string
    {
        $result = '';
        for ($i = 0; $i < strlen($pattern); $i++) {
            $char = $pattern[$i];
            $result .= match ($char) {
                '?' => chr(rand(65, 90)),
                '#' => (string) rand(0, 9),
                default => $char,
            };
        }

        return $result;
    }
}
