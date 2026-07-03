# Client Products Excel Export Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Excel report of all products linked to a client, showing original product data side by side with client-specific data (codes, names, descriptions, prices) and embedded photos.

**Architecture:** A dedicated exporter class (`ClientProductsExcelExporter`) using PhpSpreadsheet (the OpenSpout-based `AbstractExcelTemplate` cannot embed images), triggered by a header action on the existing `ClientProductsRelationManager` that streams the file as a download.

**Tech Stack:** Laravel 12, Filament 4, PhpSpreadsheet (`phpoffice/phpspreadsheet`, already installed), PHPUnit 11.

**Spec:** `docs/superpowers/specs/2026-07-03-client-products-excel-export-design.md`

**IMPORTANT — Gui's workflow preference:** do NOT commit or push. Leave all changes in the working tree. Wherever this plan template would normally say "commit", run `vendor/bin/pint --dirty --format agent` instead and move on.

**Domain facts the engineer needs:**
- Prices are stored in **minor units with scale 10000** (4 implied decimals). `App\Domain\Infrastructure\Support\Money::toMajor(int)` converts to float, `Money::toMinor(float)` converts back.
- Products link to companies through the `company_product` pivot (`App\Domain\Catalog\Models\CompanyProduct`, table `company_product`). `Company::clientProducts()` is a BelongsToMany already filtered to `role = 'client'` with all pivot columns in `withPivot`.
- Pivot photo: `avatar_path` + `avatar_disk` (nullable). Original product photo: `Product::$avatar` (path on `public` disk, nullable).
- Tests: PHPUnit classes in `tests/Feature/...`, using `RefreshDatabase`, `Gate::before(fn () => true)` for permissions, and `Livewire::test()` for Filament components (see `tests/Feature/Catalog/ProductCloneTest.php` for the house style).

---

### Task 1: `ClientProductsExcelExporter`

**Files:**
- Create: `app/Domain/Catalog/Reports/ClientProductsExcelExporter.php`
- Test: `tests/Feature/Catalog/ClientProductsExcelExporterTest.php`

- [x] **Step 1.1: Write the failing test**

Create `tests/Feature/Catalog/ClientProductsExcelExporterTest.php`:

```php
<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Reports\ClientProductsExcelExporter;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ClientProductsExcelExporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_exports_client_products_with_original_and_client_data(): void
    {
        $client = Company::factory()->create(['name' => 'Eletro Brasil']);

        $productA = Product::factory()->create([
            'name' => 'AAA LED Panel 600x600',
            'sku' => 'SKU-A',
            'model_number' => 'MOD-A',
            'description' => 'Original description A',
        ]);
        $productB = Product::factory()->create([
            'name' => 'BBB Solar Cable 6mm',
            'sku' => 'SKU-B',
            'model_number' => 'MOD-B',
        ]);

        CompanyProduct::create([
            'company_id' => $client->id,
            'product_id' => $productA->id,
            'role' => 'client',
            'external_code' => 'CLI-001',
            'external_name' => 'Painel LED do Cliente',
            'external_description' => 'Descrição do cliente A',
            'unit_price' => Money::toMinor(12.5),
            'custom_price' => Money::toMinor(11.99),
            'currency_code' => 'USD',
        ]);

        // Client link with empty client-side data and no custom price.
        CompanyProduct::create([
            'company_id' => $client->id,
            'product_id' => $productB->id,
            'role' => 'client',
            'unit_price' => 0,
        ]);

        // Supplier-role link must NOT appear in the client report.
        $supplierOnly = Product::factory()->create(['name' => 'CCC Supplier Only']);
        CompanyProduct::create([
            'company_id' => $client->id,
            'product_id' => $supplierOnly->id,
            'role' => 'supplier',
            'unit_price' => 0,
        ]);

        $path = (new ClientProductsExcelExporter)->export($client);

        $this->assertFileExists($path);

        $sheet = IOFactory::load($path)->getActiveSheet();

        // Title and headers.
        $this->assertSame('Produtos — Eletro Brasil', $sheet->getCell('A1')->getValue());
        $this->assertSame('SKU', $sheet->getCell('B4')->getValue());
        $this->assertSame('Código do Cliente', $sheet->getCell('G4')->getValue());
        $this->assertSame('Preço de Venda', $sheet->getCell('J4')->getValue());

        // Data rows start at row 5, ordered by product name (AAA before BBB).
        $this->assertSame('SKU-A', $sheet->getCell('B5')->getValue());
        $this->assertSame('MOD-A', $sheet->getCell('C5')->getValue());
        $this->assertSame('AAA LED Panel 600x600', $sheet->getCell('D5')->getValue());
        $this->assertSame('Original description A', $sheet->getCell('E5')->getValue());
        $this->assertSame('CLI-001', $sheet->getCell('G5')->getValue());
        $this->assertSame('Painel LED do Cliente', $sheet->getCell('H5')->getValue());
        $this->assertSame('Descrição do cliente A', $sheet->getCell('I5')->getValue());
        $this->assertEqualsWithDelta(12.5, $sheet->getCell('J5')->getValue(), 0.0001);
        $this->assertEqualsWithDelta(11.99, $sheet->getCell('K5')->getValue(), 0.0001);
        $this->assertSame('USD', $sheet->getCell('L5')->getValue());

        // Second product: null client fields stay empty, no CI price.
        $this->assertSame('SKU-B', $sheet->getCell('B6')->getValue());
        $this->assertNull($sheet->getCell('G6')->getValue());
        $this->assertNull($sheet->getCell('K6')->getValue());

        // Supplier-only product excluded.
        $this->assertNull($sheet->getCell('B7')->getValue());

        unlink($path);
    }

    public function test_exports_valid_file_for_client_without_products(): void
    {
        $client = Company::factory()->create(['name' => 'Sem Produtos Ltda']);

        $path = (new ClientProductsExcelExporter)->export($client);

        $this->assertFileExists($path);

        $sheet = IOFactory::load($path)->getActiveSheet();

        $this->assertSame('Produtos — Sem Produtos Ltda', $sheet->getCell('A1')->getValue());
        $this->assertSame('Foto', $sheet->getCell('A4')->getValue());
        $this->assertNull($sheet->getCell('B5')->getValue());

        unlink($path);
    }
}
```

- [x] **Step 1.2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Catalog/ClientProductsExcelExporterTest.php`
Expected: FAIL with `Class "App\Domain\Catalog\Reports\ClientProductsExcelExporter" not found`

- [x] **Step 1.3: Implement the exporter**

Create `app/Domain/Catalog/Reports/ClientProductsExcelExporter.php` (the `Reports` directory under `app/Domain/Catalog/` does not exist yet — create it; this mirrors `app/Domain/Financial/Reports/`):

```php
<?php

namespace App\Domain\Catalog\Reports;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Support\Money;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Relatório Excel dos produtos vinculados a um cliente: dados originais do
 * produto lado a lado com os dados cadastrados para o cliente (código, nome,
 * descrição, preços) e foto embutida.
 *
 * Uses PhpSpreadsheet instead of the OpenSpout-based AbstractExcelTemplate
 * because OpenSpout cannot embed images.
 */
class ClientProductsExcelExporter
{
    private const HEADERS = [
        'A' => 'Foto',
        'B' => 'SKU',
        'C' => 'Model No.',
        'D' => 'Nome (Original)',
        'E' => 'Descrição (Original)',
        'F' => 'Categoria',
        'G' => 'Código do Cliente',
        'H' => 'Nome (Cliente)',
        'I' => 'Descrição (Cliente)',
        'J' => 'Preço de Venda',
        'K' => 'Preço CI',
        'L' => 'Moeda',
    ];

    private const COLUMN_WIDTHS = [
        'A' => 12, 'B' => 16, 'C' => 16, 'D' => 35, 'E' => 45, 'F' => 18,
        'G' => 18, 'H' => 35, 'I' => 45, 'J' => 14, 'K' => 14, 'L' => 8,
    ];

    /**
     * Generates the report and returns the absolute path of the .xlsx file.
     */
    public function export(Company $client): string
    {
        $products = $client->clientProducts()
            ->with('category')
            ->orderBy('name')
            ->get();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Produtos');

        $this->writeHeader($sheet, $client);

        $row = 5;
        foreach ($products as $product) {
            $this->writeProductRow($sheet, $product, $row);
            $row++;
        }

        if ($row > 5) {
            $sheet->getStyle('A5:L'.($row - 1))
                ->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN);
        }

        $directory = storage_path('app/temp');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $path = $directory.'/produtos-'.Str::slug($client->name).'-'.now()->format('Y-m-d').'.xlsx';

        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    private function writeHeader(Worksheet $sheet, Company $client): void
    {
        $sheet->setCellValue('A1', 'Produtos — '.$client->name);
        $sheet->mergeCells('A1:L1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $sheet->setCellValue('A2', 'Gerado em '.now()->format('d/m/Y H:i'));
        $sheet->mergeCells('A2:L2');
        $sheet->getStyle('A2')->getFont()->setSize(10)->getColor()->setRGB('808080');

        $sheet->setCellValue('B3', 'Produto (Original)');
        $sheet->mergeCells('B3:F3');
        $sheet->setCellValue('G3', 'Dados do Cliente');
        $sheet->mergeCells('G3:I3');
        $sheet->setCellValue('J3', 'Preços');
        $sheet->mergeCells('J3:L3');
        $sheet->getStyle('A3:L3')->getFont()->setBold(true);
        $sheet->getStyle('A3:L3')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER);

        foreach (self::HEADERS as $column => $label) {
            $sheet->setCellValue($column.'4', $label);
        }

        $headerStyle = $sheet->getStyle('A4:L4');
        $headerStyle->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $headerStyle->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('4472C4');

        foreach (self::COLUMN_WIDTHS as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    private function writeProductRow(Worksheet $sheet, Product $product, int $row): void
    {
        $pivot = $product->pivot;

        $sheet->setCellValue('B'.$row, $product->sku);
        $sheet->setCellValue('C'.$row, $product->model_number);
        $sheet->setCellValue('D'.$row, $product->name);
        $sheet->setCellValue('E'.$row, $product->description);
        $sheet->setCellValue('F'.$row, $product->category?->name);
        $sheet->setCellValue('G'.$row, $pivot->external_code);
        $sheet->setCellValue('H'.$row, $pivot->external_name);
        $sheet->setCellValue('I'.$row, $pivot->external_description);
        $sheet->setCellValue('J'.$row, Money::toMajor($pivot->unit_price));

        if ($pivot->custom_price !== null) {
            $sheet->setCellValue('K'.$row, Money::toMajor($pivot->custom_price));
        }

        $sheet->setCellValue('L'.$row, $pivot->currency_code);

        $sheet->getStyle('J'.$row.':K'.$row)
            ->getNumberFormat()->setFormatCode('#,##0.0000');
        $sheet->getStyle('E'.$row)->getAlignment()->setWrapText(true);
        $sheet->getStyle('I'.$row)->getAlignment()->setWrapText(true);
        $sheet->getRowDimension($row)->setRowHeight(48);

        $this->embedPhoto($sheet, $product, $row);
    }

    /**
     * Embeds the client-specific photo when set, falling back to the original
     * product photo. Missing or unreadable files are skipped silently.
     */
    private function embedPhoto(Worksheet $sheet, Product $product, int $row): void
    {
        $pivot = $product->pivot;

        $candidates = [];

        if ($pivot->avatar_path) {
            $candidates[] = [$pivot->avatar_disk ?? 'public', $pivot->avatar_path];
        }

        if ($product->avatar) {
            $candidates[] = ['public', $product->avatar];
        }

        foreach ($candidates as [$disk, $relativePath]) {
            try {
                $absolutePath = Storage::disk($disk)->path($relativePath);

                if (! is_file($absolutePath)) {
                    continue;
                }

                $drawing = new Drawing;
                $drawing->setPath($absolutePath);
                $drawing->setHeight(56);
                $drawing->setCoordinates('A'.$row);
                $drawing->setOffsetX(5);
                $drawing->setOffsetY(4);
                $drawing->setWorksheet($sheet);

                return;
            } catch (\Throwable) {
                continue;
            }
        }
    }
}
```

- [x] **Step 1.4: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/Catalog/ClientProductsExcelExporterTest.php`
Expected: PASS (2 tests)

- [x] **Step 1.5: Format**

Run: `vendor/bin/pint --dirty --format agent`
Do NOT commit (Gui's preference: leave changes in the working tree).

---

### Task 2: "Exportar Excel" header action on the relation manager

**Files:**
- Modify: `app/Filament/Resources/CRM/Companies/RelationManagers/ClientProductsRelationManager.php` (headerActions array, around line 168)
- Test: `tests/Feature/Catalog/ClientProductsExportActionTest.php`

- [x] **Step 2.1: Write the failing test**

Create `tests/Feature/Catalog/ClientProductsExportActionTest.php`:

```php
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
```

Note: verify the `EditCompany` page class path with `ls app/Filament/Resources/CRM/Companies/Pages/` — it is `EditCompany.php` per the current tree. If `assertFileDownloaded` fails because Filament wraps the response, fall back to `->assertHasNoTableActionErrors()` plus asserting the temp file no longer exists (deleteFileAfterSend) — but try `assertFileDownloaded` first; Livewire v3 supports it for `BinaryFileResponse`.

- [x] **Step 2.2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Catalog/ClientProductsExportActionTest.php`
Expected: FAIL with "Table action [exportExcel] not found" (or similar)

- [x] **Step 2.3: Add the header action**

In `app/Filament/Resources/CRM/Companies/RelationManagers/ClientProductsRelationManager.php`:

Add the import at the top with the other `use` statements:

```php
use App\Domain\Catalog\Reports\ClientProductsExcelExporter;
```

Then add the action as the FIRST entry of the `->headerActions([...])` array (before `FlexibleProductImportAction::make(...)`):

```php
Action::make('exportExcel')
    ->label('Exportar Excel')
    ->icon('heroicon-o-arrow-down-tray')
    ->color('gray')
    ->action(function () {
        $path = (new ClientProductsExcelExporter)->export($this->getOwnerRecord());

        return response()->download($path)->deleteFileAfterSend();
    }),
```

`Filament\Actions\Action` is already imported in this file. No permission gate beyond page visibility: the export is read-only, so anyone who can see the tab can export (the other header actions gate on `edit-companies` because they mutate data).

- [x] **Step 2.4: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/Catalog/ClientProductsExportActionTest.php`
Expected: PASS (1 test)

- [x] **Step 2.5: Format and run the full Catalog feature tests**

Run: `vendor/bin/pint --dirty --format agent`
Run: `php artisan test --compact tests/Feature/Catalog`
Expected: all PASS. Do NOT commit.

---

### Task 3: Manual smoke check (optional, needs running app)

- [x] **Step 3.1:** Open a client company in the panel (`/panel`), tab *Products (Client)*, click **Exportar Excel**, open the downloaded file and confirm: title row, grouped headers, photos embedded, prices with 4 decimals.

---

## Self-review notes

- Spec coverage: entry point (Task 2), exporter + columns + styling + photos + empty-client case (Task 1), error handling for missing images (Task 1 `embedPhoto`), tests (Tasks 1–2). Filename pattern asserted in Task 2 test.
- Types consistent: `export(Company): string`; `Money::toMajor(int|null): float`; pivot accessed via `$product->pivot`.
- No placeholders; all code is complete.
