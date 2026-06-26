<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Product;
use App\Filament\Resources\Catalog\Products\Pages\ListProducts;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression guard for the product Clone (ReplicateAction).
 *
 * reference_code carries a unique index (products_reference_code_unique). The
 * replicate action copies the original $attributes, so without excluding
 * reference_code the cloned insert violates that constraint. In production
 * (APP_DEBUG=false) the exception is hidden behind a generic notification, so
 * this test reproduces the failing path with a real reference_code present.
 */
class ProductCloneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->actingAs($user);
        Gate::before(fn () => true);
    }

    public function test_cloning_a_product_with_a_reference_code_does_not_violate_unique_index(): void
    {
        $original = Product::factory()->create([
            'name' => 'LED Panel 600x600',
            'reference_code' => 'REF-UNIQUE-001',
            'status' => ProductStatus::ACTIVE,
        ]);

        Livewire::test(ListProducts::class)
            ->callTableAction('replicate', $original)
            ->assertHasNoTableActionErrors();

        $clone = Product::query()
            ->where('id', '!=', $original->id)
            ->where('name', 'LED Panel 600x600 (Copy)')
            ->firstOrFail();

        // Unique columns must be cleared so the insert and future edits stay valid.
        $this->assertNull($clone->reference_code);
        $this->assertNotSame($original->sku, $clone->sku);
        $this->assertNotNull($clone->sku);
        $this->assertSame(ProductStatus::DRAFT, $clone->status);

        // Original is untouched.
        $this->assertSame('REF-UNIQUE-001', $original->fresh()->reference_code);
    }
}
