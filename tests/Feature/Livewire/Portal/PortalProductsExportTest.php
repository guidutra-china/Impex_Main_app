<?php

namespace Tests\Feature\Livewire\Portal;

use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Reports\ClientProductsExcelExporter;
use App\Domain\CRM\Models\Company;
use App\Filament\Portal\Resources\ProductResource\Pages\ListProducts;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * O cliente pode baixar do portal o mesmo relatório Excel de produtos que a
 * equipe gera em Companies → Products (Client). Usuários de portal sem a
 * permissão financeira recebem o relatório sem as colunas de preço.
 */
class PortalProductsExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('portal');

        Permission::firstOrCreate(['name' => 'portal:view-products', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'portal:view-financial-summary', 'guard_name' => 'web']);
    }

    private function actingAsPortalUser(Company $company, array $permissions): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->givePermissionTo($permissions);
        $this->actingAs($user);

        Filament::setTenant($company);

        return $user;
    }

    private function createClientProduct(Company $company): CompanyProduct
    {
        $product = Product::factory()->create();

        return CompanyProduct::create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'role' => 'client',
            'external_code' => 'CLI-001',
            'external_name' => 'Client Widget',
            'unit_price' => 1_2345,
            'currency_code' => 'USD',
        ]);
    }

    public function test_export_action_downloads_xlsx_report(): void
    {
        $company = Company::factory()->create();
        $this->actingAsPortalUser($company, ['portal:view-products', 'portal:view-financial-summary']);
        $this->createClientProduct($company);

        $expectedFilename = 'produtos-'.\Illuminate\Support\Str::slug($company->name).'-'.now()->format('Y-m-d').'.xlsx';

        Livewire::test(ListProducts::class)
            ->assertOk()
            ->callTableAction('exportExcel')
            ->assertFileDownloaded($expectedFilename);
    }

    public function test_export_action_available_without_financial_permission(): void
    {
        $company = Company::factory()->create();
        $this->actingAsPortalUser($company, ['portal:view-products']);
        $this->createClientProduct($company);

        Livewire::test(ListProducts::class)
            ->assertOk()
            ->callTableAction('exportExcel')
            ->assertFileDownloaded();
    }

    public function test_exporter_omits_prices_when_include_prices_is_false(): void
    {
        $company = Company::factory()->create();
        $this->createClientProduct($company);

        $path = (new ClientProductsExcelExporter)->export($company->fresh(), includePrices: false);

        try {
            $sheet = IOFactory::load($path)->getActiveSheet();

            // First data row is 5: prices (J), custom price (K) and currency (L) blank.
            $this->assertNull($sheet->getCell('J5')->getValue());
            $this->assertNull($sheet->getCell('K5')->getValue());
            $this->assertNull($sheet->getCell('L5')->getValue());

            // Non-financial columns keep working.
            $this->assertSame('CLI-001', $sheet->getCell('G5')->getValue());
        } finally {
            @unlink($path);
        }
    }

    public function test_exporter_includes_prices_by_default(): void
    {
        $company = Company::factory()->create();
        $this->createClientProduct($company);

        $path = (new ClientProductsExcelExporter)->export($company->fresh());

        try {
            $sheet = IOFactory::load($path)->getActiveSheet();

            $this->assertEqualsWithDelta(1.2345, (float) $sheet->getCell('J5')->getValue(), 0.0001);
            $this->assertSame('USD', $sheet->getCell('L5')->getValue());
        } finally {
            @unlink($path);
        }
    }
}
