<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Reports\ClientProductsExcelExporter;
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

    public function test_import_report_action_updates_client_links(): void
    {
        $client = Company::factory()->create(['name' => 'Eletro Brasil']);
        $client->companyRoles()->create(['role' => 'client']);

        $product = Product::factory()->create(['name' => 'LED Panel', 'sku' => 'SKU-A']);
        $link = CompanyProduct::create([
            'company_id' => $client->id,
            'product_id' => $product->id,
            'role' => 'client',
            'external_code' => 'OLD-CODE',
            'unit_price' => 0,
        ]);

        // Generate the report, edit it, and stage it as an uploaded file.
        $exportPath = (new ClientProductsExcelExporter)->export($client);
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($exportPath);
        $spreadsheet->getActiveSheet()->setCellValue('G5', 'NEW-CODE');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($exportPath);
        $spreadsheet->disconnectWorksheets();

        $storedFile = 'temp-imports/'.basename($exportPath);
        \Illuminate\Support\Facades\Storage::disk('local')->put($storedFile, file_get_contents($exportPath));
        unlink($exportPath);

        Livewire::test(ClientProductsRelationManager::class, [
            'ownerRecord' => $client,
            'pageClass' => EditCompany::class,
        ])
            ->callTableAction('importReport', data: ['file' => [$storedFile]])
            ->assertHasNoTableActionErrors();

        $this->assertSame('NEW-CODE', $link->refresh()->external_code);
        $this->assertFalse(\Illuminate\Support\Facades\Storage::disk('local')->exists($storedFile));
    }
}
