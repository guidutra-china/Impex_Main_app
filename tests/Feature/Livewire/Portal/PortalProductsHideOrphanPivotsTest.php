<?php

namespace Tests\Feature\Livewire\Portal;

use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Filament\Portal\Resources\ProductResource\Pages\ListProducts;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Pivô cliente-produto cujo produto foi soft-deletado não pode aparecer no
 * portal — renderizava uma linha em branco só com o preço do pivô.
 */
class PortalProductsHideOrphanPivotsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('portal');
        Permission::firstOrCreate(['name' => 'portal:view-products', 'guard_name' => 'web']);
    }

    public function test_pivot_of_soft_deleted_product_is_hidden(): void
    {
        $company = Company::factory()->create();

        $user = User::factory()->create(['company_id' => $company->id]);
        $user->givePermissionTo('portal:view-products');
        $this->actingAs($user);
        Filament::setTenant($company);

        $liveProduct = Product::factory()->create(['name' => 'Massage Chair A19B']);
        $live = CompanyProduct::create([
            'company_id' => $company->id, 'product_id' => $liveProduct->id,
            'role' => 'client', 'unit_price' => 2_580_000,
        ]);

        $deletedProduct = Product::factory()->create(['name' => 'Massage Chair DUP']);
        $orphan = CompanyProduct::create([
            'company_id' => $company->id, 'product_id' => $deletedProduct->id,
            'role' => 'client', 'unit_price' => 2_580_000,
        ]);
        $deletedProduct->delete();

        Livewire::test(ListProducts::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$live])
            ->assertCanNotSeeTableRecords([$orphan]);
    }
}
