<?php

namespace Tests\Feature\Documents;

use App\Domain\Infrastructure\Pdf\Templates\CommercialInvoicePdfTemplate;
use App\Domain\Infrastructure\Pdf\Templates\PackingListPdfTemplate;
use App\Domain\Infrastructure\Services\DocumentService;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Reports\CommercialInvoiceExcelExporter;
use App\Domain\Logistics\Reports\PackingListExcelExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * "SH-2026-00027 (v1)" colado na referência acabava copiado para documentos
 * oficiais do cliente. A versão vai em linha própria, rotulada — em todo
 * PDF e no cabeçalho do Excel (com a versão da própria planilha).
 */
class VersionOnItsOwnLineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_no_pdf_blade_glues_the_version_to_the_reference(): void
    {
        foreach (glob(resource_path('views/pdf/*.blade.php')) as $blade) {
            $source = file_get_contents($blade);

            $this->assertStringNotContainsString('(v{{ $document_version }})', $source, basename($blade));

            if (str_contains($source, '$document_version')) {
                $this->assertStringContainsString("\$labels['version']", $source, basename($blade).' imprime a versão sem rótulo próprio');
            }
        }
    }

    public function test_commercial_invoice_and_packing_list_pdfs_print_the_version_on_a_separate_row(): void
    {
        $shipment = Shipment::factory()->create(['reference' => 'SH-2026-00027']);

        foreach ([CommercialInvoicePdfTemplate::class, PackingListPdfTemplate::class] as $templateClass) {
            $template = new $templateClass($shipment->fresh(), 'en');
            $html = view($template->getView(), $template->getData())->render();

            $this->assertStringNotContainsString('SH-2026-00027 (v', $html, $templateClass);
            $this->assertMatchesRegularExpression('~<td class="meta-value">SH-2026-00027</td>~', $html, $templateClass);
            $this->assertMatchesRegularExpression('~<td class="meta-label">Version</td>\s*<td class="meta-value">v1</td>~', $html, $templateClass);
        }
    }

    public function test_excel_header_carries_the_spreadsheets_own_version(): void
    {
        $shipment = Shipment::factory()->create(['reference' => 'SH-2026-00027']);

        $this->assertSame('v1', $this->excelVersion((new CommercialInvoiceExcelExporter)->export($shipment)));
        $this->assertSame('v1', $this->excelVersion((new PackingListExcelExporter)->export($shipment)));

        // Um PDF da CI arquivado NÃO mexe na versão da planilha; um Excel arquivado sim.
        (new DocumentService)->storeGenerated($shipment, 'pdf', 'commercial_invoice_pdf', 'CI-SH-2026-00027-v1.pdf');
        $this->assertSame('v1', $this->excelVersion((new CommercialInvoiceExcelExporter)->export($shipment->fresh())));

        (new DocumentService)->storeGenerated($shipment, 'xlsx', CommercialInvoiceExcelExporter::DOCUMENT_TYPE, 'CI-SH-2026-00027-v1.xlsx', 'xlsx');
        $this->assertSame('v2', $this->excelVersion((new CommercialInvoiceExcelExporter)->export($shipment->fresh())));
        $this->assertSame('v1', $this->excelVersion((new PackingListExcelExporter)->export($shipment->fresh())));
    }

    private function excelVersion(string $path): string
    {
        $rows = IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false);
        @unlink($path);

        foreach ($rows as $row) {
            $cells = array_values(array_map(fn ($c) => trim((string) $c), $row));
            $index = array_search('Version', $cells, true);

            if ($index !== false) {
                // A referência fica na linha anterior, sem versão colada.
                return $cells[$index + 1];
            }
        }

        $this->fail('Linha "Version" não encontrada no cabeçalho da planilha.');
    }
}
