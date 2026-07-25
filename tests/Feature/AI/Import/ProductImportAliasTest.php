<?php

declare(strict_types=1);

namespace Tests\Feature\AI\Import;

use App\Domain\AI\Import\Models\ProductImportAlias;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductImportAliasTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_alias_and_enforces_unique_per_company(): void
    {
        $company = Company::factory()->create();
        $product = Product::factory()->create();

        ProductImportAlias::create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'alias' => 'Hex rubber dumbbell — 5kg',
            'alias_normalized' => 'HEXRUBBERDUMBBELL5KG',
            'source' => 'import_confirm',
            'last_confirmed_at' => now(),
        ]);

        $this->assertDatabaseCount('product_import_aliases', 1);

        ProductImportAlias::create([
            'company_id' => Company::factory()->create()->id,
            'product_id' => $product->id,
            'alias' => 'Hex rubber dumbbell — 5kg',
            'alias_normalized' => 'HEXRUBBERDUMBBELL5KG',
            'source' => 'import_confirm',
            'last_confirmed_at' => now(),
        ]);

        $this->assertDatabaseCount('product_import_aliases', 2);

        $this->expectException(QueryException::class);

        ProductImportAlias::create([
            'company_id' => $company->id,
            'product_id' => Product::factory()->create()->id,
            'alias' => 'HEX RUBBER DUMBBELL 5kg',
            'alias_normalized' => 'HEXRUBBERDUMBBELL5KG',
            'source' => 'import_confirm',
            'last_confirmed_at' => now(),
        ]);
    }
}
