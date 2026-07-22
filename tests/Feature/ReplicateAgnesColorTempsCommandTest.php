<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\CategoryAttribute;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReplicateAgnesColorTempsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeSourceProduct(): Product
    {
        // No category: replicas fall back to the sqlite-safe DRF- SKU path in tests.
        $source = Product::factory()->create([
            'name' => 'Agnes-40W - 4000K',
            'sku' => 'STL-00004',
            'model_number' => 'AGN7040-D4',
            'reference_code' => 'AGN-40D4',
            'product_family' => 'Agnes 4000k',
            'brand' => 'AGNES',
            'category_id' => null,
        ]);

        $source->specification()->create(['net_weight' => 5.5, 'material' => 'Aluminium']);

        $category = Category::create(['name' => 'Street Lights', 'slug' => 'street-lights', 'is_active' => true]);
        $cct = CategoryAttribute::create(['category_id' => $category->id, 'name' => 'CCT', 'type' => 'text']);
        $power = CategoryAttribute::create(['category_id' => $category->id, 'name' => 'Power', 'type' => 'text']);
        $source->attributeValues()->create(['category_attribute_id' => $cct->id, 'value' => '4000']);
        $source->attributeValues()->create(['category_attribute_id' => $power->id, 'value' => '40']);

        $client = Company::factory()->create(['name' => 'TRADETEK']);
        $supplier = Company::factory()->create(['name' => 'Freelux']);
        $source->companies()->attach($client->id, [
            'role' => 'client', 'external_code' => 'AGN740-D4',
            'external_name' => 'AGNES 40W 4000K', 'unit_price' => 179275, 'is_preferred' => true,
        ]);
        $source->companies()->attach($supplier->id, [
            'role' => 'supplier', 'external_code' => 'ESL009-40W-4K', 'unit_price' => 177500,
        ]);

        return $source;
    }

    public function test_replicates_each_source_into_d3_and_d2_with_transformed_data(): void
    {
        $this->makeSourceProduct();

        $this->artisan('products:replicate-agnes-color-temps')
            ->expectsOutputToContain('2 product(s) created, 0 skipped')
            ->assertSuccessful();

        $d3 = Product::where('model_number', 'AGN7040-D3')->first();
        $d2 = Product::where('model_number', 'AGN7040-D2')->first();

        $this->assertNotNull($d3);
        $this->assertNotNull($d2);

        $this->assertSame('Agnes-40W - 3000K', $d3->name);
        $this->assertSame('AGN-40D3', $d3->reference_code);
        $this->assertSame('Agnes 3000k', $d3->product_family);
        $this->assertSame('Agnes-40W - 2700K', $d2->name);
        $this->assertSame('AGN-40D2', $d2->reference_code);
        $this->assertSame('Agnes 2700k', $d2->product_family);
        $this->assertNotNull($d3->sku);
        $this->assertNotSame('STL-00004', $d3->sku);

        // Specification copied.
        $this->assertSame('Aluminium', $d3->specification->material);

        // CCT transformed, other attributes copied verbatim.
        $d3Attrs = $d3->attributeValues()->with('categoryAttribute')->get()
            ->mapWithKeys(fn ($av) => [$av->categoryAttribute->name => $av->value]);
        $this->assertSame('3000', $d3Attrs['CCT']);
        $this->assertSame('40', $d3Attrs['Power']);
        $this->assertSame('2700', $d2->attributeValues()->with('categoryAttribute')->get()
            ->firstWhere('categoryAttribute.name', 'CCT')->value);

        // Company links copied with transformed external codes and same prices.
        $d3Client = $d3->companies()->wherePivot('role', 'client')->first();
        $this->assertSame('AGN740-D3', $d3Client->pivot->external_code);
        $this->assertSame('AGNES 40W 3000K', $d3Client->pivot->external_name);
        $this->assertSame(179275, (int) $d3Client->pivot->unit_price);

        $d3Supplier = $d3->companies()->wherePivot('role', 'supplier')->first();
        $this->assertSame('ESL009-40W-3K', $d3Supplier->pivot->external_code);

        $d2Client = $d2->companies()->wherePivot('role', 'client')->first();
        $this->assertSame('AGN740-D2', $d2Client->pivot->external_code);
        $this->assertSame('AGNES 40W 2700K', $d2Client->pivot->external_name);

        $d2Supplier = $d2->companies()->wherePivot('role', 'supplier')->first();
        $this->assertSame('ESL009-40W-27K', $d2Supplier->pivot->external_code);
    }

    public function test_rerun_is_idempotent_and_skips_existing_replicas(): void
    {
        $this->makeSourceProduct();

        $this->artisan('products:replicate-agnes-color-temps')->assertSuccessful();
        $this->assertSame(3, Product::count());

        $this->artisan('products:replicate-agnes-color-temps')
            ->expectsOutputToContain('0 product(s) created, 2 skipped')
            ->assertSuccessful();

        $this->assertSame(3, Product::count());
    }

    public function test_dry_run_creates_nothing(): void
    {
        $this->makeSourceProduct();

        $this->artisan('products:replicate-agnes-color-temps', ['--dry-run' => true])
            ->expectsOutputToContain('[DRY RUN] 2 product(s) created')
            ->assertSuccessful();

        $this->assertSame(1, Product::count());
        $this->assertNull(Product::where('model_number', 'AGN7040-D3')->first());
    }
}
