<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Filament\Resources\CRM\Companies\Pages\EditCompany;
use App\Filament\Resources\CRM\Companies\RelationManagers\ClientProductsRelationManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class ClientProductsExportActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        Gate::before(fn () => true);
    }

    public function test_export_excel_action_downloads_the_report(): void
    {
        $client = Company::factory()->create(['name' => 'Eletro Brasil']);
        $client->companyRoles()->create(['role' => 'client']);

        $product = Product::factory()->create(['name' => 'LED Panel']);
        CompanyProduct::create([
            'company_id' => $client->id,
            'product_id' => $product->id,
            'role' => 'client',
            'external_code' => 'CLI-001',
            'unit_price' => 0,
        ]);

        Livewire::test(ClientProductsRelationManager::class, [
            'ownerRecord' => $client,
            'pageClass' => EditCompany::class,
        ])
            ->callTableAction('exportExcel')
            ->assertHasNoTableActionErrors()
            ->assertFileDownloaded('produtos-eletro-brasil-'.now()->format('Y-m-d').'.xlsx');
    }
}
