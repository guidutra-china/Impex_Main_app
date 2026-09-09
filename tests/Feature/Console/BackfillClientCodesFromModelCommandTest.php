<?php

namespace Tests\Feature\Console;

use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillClientCodesFromModelCommandTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    private Company $supplier;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Company::factory()->create(['name' => 'DEEP FITNESS']);
        $this->supplier = Company::factory()->create(['name' => 'JiongGong Fitness Equipment Co.,Ltd.']);
        $this->file = tempnam(sys_get_temp_dir(), 'codes').'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);

        parent::tearDown();
    }

    /** @param  array<int, array<string, mixed>>  $products */
    private function writeFile(array $products, array $overrides = []): void
    {
        file_put_contents($this->file, json_encode(array_merge([
            'client' => 'DEEP FITNESS',
            'suppliers' => ['JiongGong Fitness Equipment Co.,Ltd.'],
            'from_prefix' => 'JG-',
            'to_prefix' => 'DF-',
            'products' => $products,
        ], $overrides)));
    }

    private function clientPivot(Product $product): ?CompanyProduct
    {
        return CompanyProduct::query()
            ->where('company_id', $this->client->id)
            ->where('product_id', $product->id)
            ->where('role', 'client')
            ->first();
    }

    public function test_fills_model_number_and_client_code_and_is_dry_run_by_default(): void
    {
        // Produto novo do import: model vazio, reference_code = código do fornecedor, pivot cliente vazio.
        $fresh = Product::factory()->create(['model_number' => null, 'reference_code' => 'JG-S6727']);
        $fresh->companies()->attach($this->client->id, ['role' => 'client']);
        $fresh->companies()->attach($this->supplier->id, ['role' => 'supplier', 'external_code' => 'JG-S6727']);

        // Produto antigo: model preenchido, sem pivot de cliente.
        $old = Product::factory()->create(['model_number' => 'JG-1901', 'reference_code' => 'JG-1901']);

        $this->writeFile([
            ['model' => 'JG-S6727'],
            ['model' => 'JG-1901'],
        ]);

        $this->artisan('products:backfill-client-codes', ['file' => $this->file])->assertSuccessful();

        $this->assertNull($fresh->fresh()->model_number, 'dry-run must not persist');
        $this->assertNull($this->clientPivot($old));

        $this->artisan('products:backfill-client-codes', ['file' => $this->file, '--apply' => true])->assertSuccessful();

        $this->assertSame('JG-S6727', $fresh->fresh()->model_number);
        $this->assertSame('DF-S6727', $this->clientPivot($fresh)->external_code);
        $this->assertSame('JG-1901', $old->fresh()->model_number);
        $this->assertSame('DF-1901', $this->clientPivot($old)->external_code);

        // Idempotente: segunda execução não muda nada e continua bem-sucedida.
        $this->artisan('products:backfill-client-codes', ['file' => $this->file, '--apply' => true])->assertSuccessful();
        $this->assertSame('DF-S6727', $this->clientPivot($fresh)->external_code);
    }

    public function test_finds_by_supplier_code_alias_and_replaces_declared_wrong_model(): void
    {
        // Fornecedor fundido em outro: soft-deletado, mas os pivots com o código ainda vivem nele.
        $this->supplier->delete();

        $plate = Product::factory()->create(['model_number' => 'JG-PG- 2.5', 'reference_code' => 'JG-PJ-25KG']);
        $plate->companies()->attach($this->supplier->id, ['role' => 'supplier', 'external_code' => 'JG-PJ-25KG']);

        $bar = Product::factory()->create(['model_number' => 'JG-Z22700', 'reference_code' => null]);
        $bar->companies()->attach($this->supplier->id, ['role' => 'supplier', 'external_code' => 'Z227']);

        $this->writeFile([
            ['model' => 'JG-PJ-2.5', 'find' => ['JG-PJ-25KG'], 'replaces_model' => 'JG-PG- 2.5'],
            ['model' => 'JG-Z22700', 'find' => ['Z227']],
        ]);

        $this->artisan('products:backfill-client-codes', ['file' => $this->file, '--apply' => true])->assertSuccessful();

        $this->assertSame('JG-PJ-2.5', $plate->fresh()->model_number);
        $this->assertSame('DF-PJ-2.5', $this->clientPivot($plate)->external_code);
        $this->assertSame('DF-Z22700', $this->clientPivot($bar)->external_code);
    }

    public function test_never_overwrites_a_different_existing_code_unless_only_the_prefix_differs(): void
    {
        $prefixed = Product::factory()->create(['model_number' => 'JG-1646']);
        $prefixed->companies()->attach($this->client->id, ['role' => 'client', 'external_code' => 'DPF-1646']);

        $unrelated = Product::factory()->create(['model_number' => 'JG-1910']);
        $unrelated->companies()->attach($this->client->id, ['role' => 'client', 'external_code' => 'KEEP-ME']);

        $wrongModel = Product::factory()->create(['model_number' => 'JG-9999', 'reference_code' => 'JG-1908']);

        $this->writeFile([
            ['model' => 'JG-1646'],
            ['model' => 'JG-1910'],
            ['model' => 'JG-1908'],
        ]);

        $this->artisan('products:backfill-client-codes', ['file' => $this->file, '--apply' => true])->assertSuccessful();

        $this->assertSame('DPF-1646', $this->clientPivot($prefixed)->external_code);
        $this->assertSame('KEEP-ME', $this->clientPivot($unrelated)->external_code);
        // model divergente sem replaces_model: model fica, mas o código do cliente é gravado mesmo assim.
        $this->assertSame('JG-9999', $wrongModel->fresh()->model_number);
        $this->assertSame('DF-1908', $this->clientPivot($wrongModel)->external_code);

        $this->artisan('products:backfill-client-codes', [
            'file' => $this->file, '--apply' => true, '--overwrite-prefix' => true,
        ])->assertSuccessful();

        $this->assertSame('DF-1646', $this->clientPivot($prefixed)->external_code);
        $this->assertSame('KEEP-ME', $this->clientPivot($unrelated)->external_code);
    }

    public function test_reports_missing_and_ambiguous_products_without_failing_the_others(): void
    {
        Product::factory()->create(['model_number' => 'JG-DUP', 'reference_code' => 'A-1']);
        Product::factory()->create(['model_number' => 'JG-DUP', 'reference_code' => 'A-2']);
        $ok = Product::factory()->create(['model_number' => 'JG-OK']);

        $this->writeFile([
            ['model' => 'JG-MISSING'],
            ['model' => 'JG-DUP'],
            ['model' => 'JG-OK'],
        ]);

        $this->artisan('products:backfill-client-codes', ['file' => $this->file, '--apply' => true])
            ->expectsOutputToContain('JG-MISSING')
            ->expectsOutputToContain('JG-DUP')
            ->assertSuccessful();

        $this->assertSame('DF-OK', $this->clientPivot($ok)->external_code);
        $this->assertSame(1, CompanyProduct::query()->where('company_id', $this->client->id)->count());
    }

    public function test_fails_when_client_is_not_found(): void
    {
        $this->writeFile([['model' => 'JG-1']], ['client' => 'NOBODY']);

        $this->artisan('products:backfill-client-codes', ['file' => $this->file])->assertFailed();
    }
}
