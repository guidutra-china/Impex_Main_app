<?php

namespace Tests\Feature\ProformaInvoices;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Infrastructure\Models\DocumentVersion;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\ProformaInvoices\Reports\ProformaInvoiceExcelExporter;
use App\Domain\Quotations\Enums\CommissionType;
use App\Filament\Resources\ProformaInvoices\Pages\ViewProformaInvoice;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * PI em Excel: mesmo payload do PDF (preços, códigos do cliente, custos do
 * cliente), arquivada na aba Documents com versionamento próprio (…_xlsx).
 * Preço alterado (fórmula / preço especial) vai para um tipo à parte, como
 * no PDF, para não avançar a versão da PI oficial.
 */
class ProformaInvoiceExcelExportTest extends TestCase
{
    use RefreshDatabase;

    private ProformaInvoice $pi;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $admin->id ? true : null);
        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');
        Storage::fake('local');

        $client = Company::factory()->create(['name' => 'Deep Fitness']);
        $this->pi = ProformaInvoice::factory()->create(['company_id' => $client->id, 'currency_code' => 'USD']);

        $withCode = Product::factory()->create(['name' => 'Treadmill', 'model_number' => 'MOD-X', 'sku' => 'SKU-X']);
        $withCode->companies()->attach($client->id, ['role' => 'client', 'external_code' => 'CLIENT-77']);
        $plain = Product::factory()->create(['name' => 'Bike', 'model_number' => 'MOD-Y', 'sku' => 'SKU-Y']);

        foreach ([[$withCode, 'Treadmill', 10, 100.00], [$plain, 'Bike', 4, 250.50]] as $i => [$product, $description, $qty, $price]) {
            ProformaInvoiceItem::create([
                'proforma_invoice_id' => $this->pi->id,
                'product_id' => $product->id,
                'description' => $description,
                'quantity' => $qty,
                'unit' => 'pcs',
                'unit_price' => Money::toMinor($price),
                'sort_order' => $i,
            ]);
        }

        AdditionalCost::create([
            'costable_type' => ProformaInvoice::class,
            'costable_id' => $this->pi->id,
            'cost_type' => AdditionalCostType::COMMISSION,
            'commission_mode' => CommissionType::SEPARATE,
            'description' => 'Service Fee',
            'amount' => Money::toMinor(50.00),
            'currency_code' => 'USD',
            'amount_in_document_currency' => Money::toMinor(50.00),
            'billable_to' => BillableTo::CLIENT,
            'cost_date' => now()->toDateString(),
        ]);
    }

    /** @return array<int, array<int, mixed>> linhas da planilha, colunas A.. */
    private function rows(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, false, false);
        $spreadsheet->disconnectWorksheets();

        return $rows;
    }

    /** Primeira linha que contém o texto em alguma célula. */
    private function rowWith(array $rows, string $needle): ?array
    {
        foreach ($rows as $row) {
            if (in_array($needle, $row, true)) {
                return $row;
            }
        }

        return null;
    }

    public function test_excel_carries_the_same_items_and_totals_as_the_pdf(): void
    {
        $path = (new ProformaInvoiceExcelExporter)->export($this->pi->fresh());
        $rows = $this->rows($path);
        unlink($path);

        $this->assertSame('PROFORMA INVOICE', $rows[0][0]);
        $this->assertNotNull($this->rowWith($rows, $this->pi->reference));
        $this->assertNotNull($this->rowWith($rows, 'v1'));

        $header = $this->rowWith($rows, 'MODEL NO.');
        $this->assertNotNull($header);
        $model = array_search('MODEL NO.', $header, true);
        $qty = array_search('QTY', $header, true);
        $unitPrice = array_search('UNIT USD', $header, true);
        $total = array_search('TOTAL USD', $header, true);
        // SKU é coluna opt-in, como no PDF.
        $this->assertFalse(array_search('PRODUCT CODE', $header, true));

        // Código do cliente vence; sem pivot cai no model number.
        $first = $this->rowWith($rows, 'CLIENT-77');
        $this->assertSame('CLIENT-77', $first[$model]);
        $this->assertEquals(10, $first[$qty]);
        $this->assertEqualsWithDelta(100.00, $first[$unitPrice], 0.0001);
        $this->assertEqualsWithDelta(1000.00, $first[$total], 0.0001);

        $second = $this->rowWith($rows, 'MOD-Y');
        $this->assertEqualsWithDelta(250.50, $second[$unitPrice], 0.0001);
        $this->assertEqualsWithDelta(1002.00, $second[$total], 0.0001);

        // Valores numéricos (somáveis), não texto.
        $this->assertIsNotString($first[$total]);

        $this->assertEqualsWithDelta(2002.00, $this->rowWith($rows, 'SUBTOTAL')[$total], 0.0001);
        $this->assertEqualsWithDelta(50.00, $this->rowWith($rows, 'Service Fee')[$total], 0.0001);
        $this->assertEqualsWithDelta(2052.00, $this->rowWith($rows, 'GRAND TOTAL')[$total], 0.0001);
    }

    public function test_options_follow_the_pdf_hide_fees_show_sku_and_formula(): void
    {
        $path = (new ProformaInvoiceExcelExporter)->export($this->pi->fresh(), [
            'hide_commission' => true,
            'show_product_code' => true,
            'show_model_number' => false,
            'use_formula' => true,
            'price_formula' => '*0.5',
        ]);
        $rows = $this->rows($path);
        unlink($path);

        $header = $this->rowWith($rows, 'PRODUCT CODE');
        $this->assertNotNull($header);
        $this->assertFalse(array_search('MODEL NO.', $header, true));
        $total = array_search('TOTAL USD', $header, true);

        $this->assertNull($this->rowWith($rows, 'Service Fee'));
        $this->assertEqualsWithDelta(500.00, $this->rowWith($rows, 'SKU-X')[$total], 0.0001);
        $this->assertEqualsWithDelta(1001.00, $this->rowWith($rows, 'GRAND TOTAL')[$total], 0.0001);
    }

    public function test_action_archives_the_excel_with_versioning_and_downloads_it(): void
    {
        $page = Livewire::test(ViewProformaInvoice::class, ['record' => $this->pi->getKey()]);
        $page->callAction('exportProformaInvoiceExcel')->assertFileDownloaded($this->pi->reference.'-v1.xlsx');

        $document = $this->pi->fresh()->getLatestDocument('proforma_invoice_xlsx');

        $this->assertNotNull($document);
        $this->assertSame(1, $document->version);
        $this->assertSame($this->pi->reference.'-v1.xlsx', $document->name);
        $this->assertTrue(Storage::disk('local')->exists($document->path));
        $this->assertStringContainsString('spreadsheet', (string) $document->mime_type);
        // O PDF não é tocado: linhas do tempo separadas.
        $this->assertNull($this->pi->fresh()->getLatestDocument('proforma_invoice_pdf'));

        $page->callAction('exportProformaInvoiceExcel');
        $second = $this->pi->fresh()->getLatestDocument('proforma_invoice_xlsx');

        $this->assertSame($document->id, $second->id);
        $this->assertSame(2, $second->version);
        $this->assertSame($this->pi->reference.'-v2.xlsx', $second->name);
        $this->assertCount(1, DocumentVersion::where('document_id', $document->id)->get());
    }

    public function test_excel_with_changed_prices_is_archived_apart_from_the_official_pi(): void
    {
        Livewire::test(ViewProformaInvoice::class, ['record' => $this->pi->getKey()])
            ->callAction('exportProformaInvoiceExcel', data: ['use_formula' => true, 'price_formula' => '*0.7'])
            ->assertHasNoActionErrors()
            ->assertFileDownloaded('Custom-'.$this->pi->reference.'-v1.xlsx');

        $custom = $this->pi->fresh()->getLatestDocument('custom_price_xlsx');

        $this->assertNotNull($custom);
        $this->assertSame('Custom-'.$this->pi->reference.'-v1.xlsx', $custom->name);
        $this->assertNull($this->pi->fresh()->getLatestDocument('proforma_invoice_xlsx'));
    }
}
