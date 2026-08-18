<?php

namespace Tests\Unit\Catalog;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\ProductIdentityResolver;
use App\Domain\CRM\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A regra única de identidade do produto nos documentos:
 * código da contraparte > model number > SKU interno, sempre filtrando o pivot
 * por empresa E papel.
 */
class ProductIdentityResolverTest extends TestCase
{
    use RefreshDatabase;

    private function product(array $attributes = []): Product
    {
        return Product::factory()->create(array_merge([
            'name' => 'Internal Product Name',
            'model_number' => 'MOD-1',
            'sku' => 'SKU-'.uniqid(),
        ], $attributes));
    }

    private function link(Product $product, Company $company, string $role, array $pivot = []): void
    {
        $product->companies()->attach($company->id, array_merge(['role' => $role], $pivot));
        $product->load('companies');
    }

    public function test_code_prefers_counterparty_code_then_model_then_sku(): void
    {
        $client = Company::factory()->create();

        $withCode = $this->product(['sku' => 'SKU-A']);
        $this->link($withCode, $client, 'client', ['external_code' => 'CLIENT-1']);

        $withModel = $this->product(['sku' => 'SKU-B', 'model_number' => 'MOD-B']);
        $this->link($withModel, $client, 'client');

        $skuOnly = $this->product(['sku' => 'SKU-C', 'model_number' => null]);
        $this->link($skuOnly, $client, 'client');

        $resolver = ProductIdentityResolver::forClient($client->id);

        $this->assertSame('CLIENT-1', $resolver->resolve($withCode)->code);
        $this->assertTrue($resolver->resolve($withCode)->fromCounterparty);

        $this->assertSame('MOD-B', $resolver->resolve($withModel)->code);
        $this->assertFalse($resolver->resolve($withModel)->fromCounterparty);

        $this->assertSame('SKU-C', $resolver->resolve($skuOnly)->code);
    }

    public function test_code_is_empty_string_when_nothing_resolves_and_caller_picks_placeholder(): void
    {
        // O hook de criação gera SKU quando vem vazio; esvaziamos depois para
        // simular o produto legado sem nenhum identificador.
        $product = $this->product(['model_number' => null]);
        $product->forceFill(['sku' => ''])->saveQuietly();

        $identity = ProductIdentityResolver::forClient(null)->resolve($product->fresh());

        $this->assertSame('', $identity->code);
        $this->assertSame('—', $identity->codeOr('—'));
        $this->assertSame('', $identity->codeOr(''));
    }

    public function test_role_isolation_when_the_same_company_is_client_and_supplier(): void
    {
        $company = Company::factory()->create();
        $product = $this->product();

        $product->companies()->attach($company->id, ['role' => 'client', 'external_code' => 'CLIENT-CODE', 'external_name' => 'Client Name']);
        $product->companies()->attach($company->id, ['role' => 'supplier', 'external_code' => 'SUPPLIER-CODE', 'external_name' => 'Supplier Name']);
        $product->load('companies');

        $asClient = ProductIdentityResolver::forClient($company->id)->resolve($product);
        $asSupplier = ProductIdentityResolver::forSupplier($company->id)->resolve($product);

        $this->assertSame('CLIENT-CODE', $asClient->code);
        $this->assertSame('Client Name', $asClient->name);
        $this->assertSame('SUPPLIER-CODE', $asSupplier->code);
        $this->assertSame('Supplier Name', $asSupplier->name);
    }

    public function test_company_isolation_ignores_another_companys_pivot(): void
    {
        $mine = Company::factory()->create();
        $other = Company::factory()->create();
        $product = $this->product(['model_number' => 'MOD-Z']);

        $this->link($product, $other, 'client', ['external_code' => 'OTHER-CODE']);

        $identity = ProductIdentityResolver::forClient($mine->id)->resolve($product);

        $this->assertSame('MOD-Z', $identity->code);
    }

    public function test_branch_falls_back_to_parent_company_pivot(): void
    {
        $hq = Company::factory()->create();
        $branch = Company::factory()->create();
        $product = $this->product();

        $this->link($product, $hq, 'client', ['external_code' => 'HQ-CODE']);

        $identity = ProductIdentityResolver::forClient($branch->id, $hq->id)->resolve($product);

        $this->assertSame('HQ-CODE', $identity->code);
    }

    public function test_branch_pivot_wins_over_parent_when_both_exist(): void
    {
        $hq = Company::factory()->create();
        $branch = Company::factory()->create();
        $product = $this->product();

        $product->companies()->attach($hq->id, ['role' => 'client', 'external_code' => 'HQ-CODE']);
        $product->companies()->attach($branch->id, ['role' => 'client', 'external_code' => 'BRANCH-CODE']);
        $product->load('companies');

        $identity = ProductIdentityResolver::forClient($branch->id, $hq->id)->resolve($product);

        $this->assertSame('BRANCH-CODE', $identity->code);
    }

    public function test_name_prefers_counterparty_name_then_line_snapshot_then_product_name(): void
    {
        $client = Company::factory()->create();

        $named = $this->product();
        $this->link($named, $client, 'client', ['external_name' => 'Client Calls It This']);

        $plain = $this->product();
        $this->link($plain, $client, 'client');

        $resolver = ProductIdentityResolver::forClient($client->id);

        $this->assertSame('Client Calls It This', $resolver->resolve($named, lineName: 'Line Name')->name);
        $this->assertSame('Line Name', $resolver->resolve($plain, lineName: 'Line Name')->name);
        $this->assertSame('Internal Product Name', $resolver->resolve($plain)->name);
    }

    public function test_null_product_returns_line_values_without_touching_the_pivot(): void
    {
        $identity = ProductIdentityResolver::forClient(1)->resolve(null, lineName: 'Free text line', lineDescription: 'Desc');

        $this->assertSame('', $identity->code);
        $this->assertSame('Free text line', $identity->name);
        $this->assertSame('Desc', $identity->description);
    }

    public function test_pivot_is_null_and_reported_when_companies_relation_is_not_loaded(): void
    {
        $client = Company::factory()->create();
        $product = $this->product(['model_number' => 'MOD-UNLOADED']);
        $product->companies()->attach($client->id, ['role' => 'client', 'external_code' => 'SHOULD-NOT-APPEAR']);

        // Instância "fresca" sem eager-load — o contrato do resolver é não consultar o banco.
        $unloaded = Product::withoutGlobalScopes()->findOrFail($product->id);

        $identity = ProductIdentityResolver::forClient($client->id)->resolve($unloaded);

        $this->assertSame('MOD-UNLOADED', $identity->code);
    }

    public function test_warm_caches_pivots_so_repeated_resolution_does_not_requery(): void
    {
        $client = Company::factory()->create();
        $product = $this->product();
        $this->link($product, $client, 'client', ['external_code' => 'CACHED']);

        $resolver = ProductIdentityResolver::forClient($client->id);
        $resolver->warm([$product, null]);

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->assertSame('CACHED', $resolver->resolve($product)->code);
        $this->assertSame('CACHED', $resolver->resolve($product)->code);
        $queries = \Illuminate\Support\Facades\DB::getQueryLog();
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertCount(0, $queries, 'O resolver não pode consultar o banco.');
    }
}
