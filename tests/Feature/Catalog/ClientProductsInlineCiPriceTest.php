<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Support\Money;
use App\Filament\Resources\CRM\Companies\Pages\EditCompany;
use App\Filament\Resources\CRM\Companies\RelationManagers\ClientProductsRelationManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * O CI Price (custom_price do vínculo cliente↔produto) é editável direto na
 * lista: digita em moeda (major), grava em inteiro na escala do Money.
 */
class ClientProductsInlineCiPriceTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    private Product $product;

    private CompanyProduct $link;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Company::factory()->create(['name' => 'Eletro Brasil']);
        $this->client->companyRoles()->create(['role' => 'client']);

        $this->product = Product::factory()->create(['name' => 'LED Panel', 'sku' => 'SKU-A']);
        $this->link = CompanyProduct::create([
            'company_id' => $this->client->id,
            'product_id' => $this->product->id,
            'role' => 'client',
            'unit_price' => Money::toMinor(10.00),
            'custom_price' => Money::toMinor(9.50),
        ]);
    }

    private function relationManager(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(ClientProductsRelationManager::class, [
            'ownerRecord' => $this->client,
            'pageClass' => EditCompany::class,
        ]);
    }

    public function test_ci_price_can_be_edited_inline_and_is_stored_in_minor_units(): void
    {
        $this->actingAs(User::factory()->create());
        Gate::before(fn () => true);

        $this->relationManager()
            ->assertTableColumnStateSet('pivot.custom_price', '9.5000', $this->product)
            ->call('updateTableColumnState', 'pivot.custom_price', (string) $this->product->getKey(), '12.3456');

        $this->assertSame(Money::toMinor(12.3456), (int) $this->link->fresh()->custom_price);
    }

    public function test_blank_clears_the_ci_price(): void
    {
        $this->actingAs(User::factory()->create());
        Gate::before(fn () => true);

        $this->relationManager()
            ->call('updateTableColumnState', 'pivot.custom_price', (string) $this->product->getKey(), '');

        $this->assertNull($this->link->fresh()->custom_price);
    }

    public function test_user_without_edit_permission_cannot_change_the_ci_price(): void
    {
        $this->actingAs(User::factory()->create());
        Gate::before(fn (User $u, string $ability) => $ability === 'edit-companies' ? false : true);

        $this->relationManager()
            ->call('updateTableColumnState', 'pivot.custom_price', (string) $this->product->getKey(), '99');

        $this->assertSame(Money::toMinor(9.50), (int) $this->link->fresh()->custom_price);
    }
}
