<?php

namespace Tests\Feature\Console;

use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairProductIdentityCommandTest extends TestCase
{
    use RefreshDatabase;

    private Company $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = Company::factory()->create(['name' => 'Sole Supplier']);
    }

    private function importedProduct(string $partNo): Product
    {
        // Assinatura do produto criado pela importação antiga de cotação.
        return Product::factory()->create([
            'reference_code' => $partNo,
            'model_number' => $partNo,
        ]);
    }

    public function test_audit_is_read_only(): void
    {
        $product = $this->importedProduct('P-100');
        $product->companies()->attach($this->supplier->id, ['role' => 'supplier']);

        $this->artisan('catalog:repair-product-identity')
            ->expectsOutputToContain('DRY-RUN')
            ->assertSuccessful();

        $this->assertNull(
            CompanyProduct::where('product_id', $product->id)->first()->external_code,
            'audit não pode gravar nada'
        );
        $this->assertSame('P-100', $product->fresh()->model_number);
    }

    public function test_supplier_codes_dry_run_writes_nothing(): void
    {
        $product = $this->importedProduct('P-200');
        $product->companies()->attach($this->supplier->id, ['role' => 'supplier']);

        $this->artisan('catalog:repair-product-identity --mode=supplier-codes')->assertSuccessful();

        $this->assertNull(CompanyProduct::where('product_id', $product->id)->first()->external_code);
    }

    public function test_supplier_codes_apply_fills_only_blank_codes(): void
    {
        $blank = $this->importedProduct('P-300');
        $blank->companies()->attach($this->supplier->id, ['role' => 'supplier']);

        $alreadySet = $this->importedProduct('P-301');
        $alreadySet->companies()->attach($this->supplier->id, ['role' => 'supplier', 'external_code' => 'KEEP-ME']);

        $this->artisan('catalog:repair-product-identity --mode=supplier-codes --apply')->assertSuccessful();

        $this->assertSame('P-300', CompanyProduct::where('product_id', $blank->id)->first()->external_code);
        $this->assertSame('KEEP-ME', CompanyProduct::where('product_id', $alreadySet->id)->first()->external_code);
    }

    public function test_products_with_two_suppliers_are_reported_as_conflicts_and_skipped(): void
    {
        $otherSupplier = Company::factory()->create();
        $product = $this->importedProduct('P-400');
        $product->companies()->attach($this->supplier->id, ['role' => 'supplier']);
        $product->companies()->attach($otherSupplier->id, ['role' => 'supplier']);

        $this->artisan('catalog:repair-product-identity --mode=supplier-codes --apply')
            ->expectsOutputToContain('conflito')
            ->assertSuccessful();

        $this->assertSame(
            0,
            CompanyProduct::where('product_id', $product->id)->whereNotNull('external_code')->count(),
            'não dá para saber de qual fornecedor é o código'
        );
    }

    public function test_skip_option_protects_a_pivot(): void
    {
        $product = $this->importedProduct('P-500');
        $product->companies()->attach($this->supplier->id, ['role' => 'supplier']);
        $pivotId = CompanyProduct::where('product_id', $product->id)->first()->id;

        $this->artisan("catalog:repair-product-identity --mode=supplier-codes --apply --skip={$pivotId}")
            ->assertSuccessful();

        $this->assertNull(CompanyProduct::find($pivotId)->external_code);
    }

    public function test_clear_model_number_only_touches_products_already_recorded_on_the_supplier_pivot(): void
    {
        $recorded = $this->importedProduct('P-600');
        $recorded->companies()->attach($this->supplier->id, ['role' => 'supplier', 'external_code' => 'P-600']);

        $notRecorded = $this->importedProduct('P-601');
        $notRecorded->companies()->attach($this->supplier->id, ['role' => 'supplier']);

        $this->artisan('catalog:repair-product-identity --mode=clear-model-number --apply')->assertSuccessful();

        $this->assertNull($recorded->fresh()->model_number);
        $this->assertSame('P-601', $notRecorded->fresh()->model_number, 'sem código no pivot, o model_number é a única identidade');
    }

    public function test_client_ncm_copies_hs_code_only_for_single_client_products(): void
    {
        $client = Company::factory()->create();
        $otherClient = Company::factory()->create();

        $single = Product::factory()->create(['hs_code' => '8431.49.00']);
        $single->companies()->attach($client->id, ['role' => 'client']);

        $shared = Product::factory()->create(['hs_code' => '8431.49.00']);
        $shared->companies()->attach($client->id, ['role' => 'client']);
        $shared->companies()->attach($otherClient->id, ['role' => 'client']);

        $this->artisan('catalog:repair-product-identity --mode=client-ncm --apply')->assertSuccessful();

        // Só dígitos, como o campo da tela grava.
        $this->assertSame('84314900', CompanyProduct::where('product_id', $single->id)->first()->external_ncm);
        $this->assertSame(
            0,
            CompanyProduct::where('product_id', $shared->id)->whereNotNull('external_ncm')->count(),
            'com dois clientes não dá para assumir a mesma classificação'
        );
    }
}
