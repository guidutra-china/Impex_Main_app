<?php

namespace Tests\Feature\Logistics;

use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Models\CartonContent;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\Logistics\Models\ShipmentPallet;
use App\Domain\Logistics\Reports\CommercialInvoiceExcelExporter;
use App\Domain\Logistics\Reports\PackingListExcelExporter;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Commercial Invoice e Packing List em Excel: os exportadores consomem o mesmo
 * payload dos templates de PDF, então a planilha precisa bater com o documento
 * (itens, quantidades, valores numéricos somáveis e totais).
 */
class ShipmentDocumentsExcelExportTest extends TestCase
{
    use RefreshDatabase;

    private Shipment $shipment;

    /** @var array<string, ShipmentItem> */
    private array $items = [];

    protected function setUp(): void
    {
        parent::setUp();

        $client = Company::create(['name' => 'Excel Client', 'status' => 'active']);
        $client->companyRoles()->create(['role' => 'client']);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-XLS-1',
            'company_id' => $client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $pi = ProformaInvoice::create([
            'reference' => 'PI-XLS-1',
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-08-01',
            'status' => 'confirmed',
        ]);

        $this->shipment = Shipment::create([
            'reference' => 'SH-XLS-1',
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'status' => 'draft',
            'transport_mode' => 'sea',
            'origin_port' => 'Shenzhen',
            'destination_port' => 'Santos',
            'issue_date' => '2026-08-01',
        ]);

        foreach ([['Pulley', 10, 250000], ['Sieve', 4, 1000000]] as $i => [$name, $qty, $price]) {
            $piItem = ProformaInvoiceItem::create([
                'proforma_invoice_id' => $pi->id,
                'description' => $name,
                'quantity' => $qty,
                'unit_price' => $price,
                'unit' => 'pcs',
            ]);

            $this->items[$name] = ShipmentItem::create([
                'shipment_id' => $this->shipment->id,
                'proforma_invoice_item_id' => $piItem->id,
                'quantity' => $qty,
                'unit' => 'pcs',
                'sort_order' => $i,
            ]);
        }
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function readRows(string $path): array
    {
        $sheet = IOFactory::load($path)->getActiveSheet();
        $rows = $sheet->toArray(null, true, false, false);

        @unlink($path);

        return $rows;
    }

    public function test_commercial_invoice_excel_carries_items_and_numeric_totals(): void
    {
        $path = (new CommercialInvoiceExcelExporter)->export($this->shipment, ['use_custom_prices' => false]);

        $this->assertFileExists($path);
        $rows = $this->readRows($path);
        $flat = array_map(fn ($row) => implode('|', array_map(fn ($c) => (string) $c, $row)), $rows);
        $text = implode("\n", $flat);

        $this->assertStringContainsString('COMMERCIAL INVOICE', $text);
        $this->assertStringContainsString('SH-XLS-1', $text);
        $this->assertStringContainsString('Pulley', $text);
        $this->assertStringContainsString('Sieve', $text);

        // Linha de item: quantidade e valores gravados como número, não texto.
        $pulleyRow = collect($rows)->first(
            fn ($row) => collect($row)->contains(fn ($cell) => is_string($cell) && str_contains($cell, 'Pulley'))
        );
        $this->assertNotNull($pulleyRow);
        $this->assertContains(10, array_map(fn ($c) => is_numeric($c) ? (int) $c : $c, $pulleyRow));

        // 10 × 25.00 + 4 × 100.00 = 650.00
        $grandTotalRow = collect($rows)->first(fn ($row) => in_array('GRAND TOTAL', array_map(fn ($c) => trim((string) $c), $row), true));
        $this->assertNotNull($grandTotalRow);
        $this->assertContains(650.0, array_map(fn ($c) => is_numeric($c) ? (float) $c : $c, $grandTotalRow));
    }

    public function test_packing_list_excel_counts_a_pallet_as_one_package(): void
    {
        $pallet = ShipmentPallet::create([
            'shipment_id' => $this->shipment->id,
            'label' => 'PLT-001',
            'gross_weight' => 430.0,
            'length' => 115,
            'width' => 150,
            'height' => 100,
        ]);

        for ($i = 1; $i <= 5; $i++) {
            $carton = Carton::create([
                'shipment_id' => $this->shipment->id,
                'label' => 'BOX-'.$i,
                'packaging_type' => 'CARTON',
                'gross_weight' => 5.0,
                'net_weight' => 4.5,
                'volume' => 0.02,
                'sort_order' => $i,
                // As duas últimas caixas viajam empilhadas no pallet.
                'shipment_pallet_id' => $i > 3 ? $pallet->id : null,
            ]);

            CartonContent::create([
                'carton_id' => $carton->id,
                'shipment_item_id' => $this->items['Pulley']->id,
                'pieces' => 2,
                'sort_order' => 1,
            ]);
        }

        $path = (new PackingListExcelExporter)->export($this->shipment->fresh());
        $rows = $this->readRows($path);

        $grandTotalRow = collect($rows)->first(
            fn ($row) => collect($row)->contains(fn ($c) => is_string($c) && str_contains($c, 'GRAND TOTAL'))
        );
        $this->assertNotNull($grandTotalRow);

        // Coluna F (índice 5) = PKG QTY: 3 caixas soltas + 1 pallet = 4 bultos.
        $this->assertEquals(4, (int) $grandTotalRow[5]);
        // O rótulo explica a conta para quem lê a planilha.
        $this->assertStringContainsString('3 CTN + 1 PLT', (string) $grandTotalRow[0]);
        $this->assertStringContainsString('5 CTN TOTAL', (string) $grandTotalRow[0]);

        // O pallet tem linha própria, com o peso pesado e o cubo do conjunto.
        // A linha do pallet começa pelo label dele; as caixas em cima só o citam no fim.
        $palletRow = collect($rows)->first(
            fn ($row) => is_string($row[0] ?? null) && str_starts_with($row[0], 'PLT-001')
        );
        $this->assertNotNull($palletRow);
        $this->assertStringContainsString('PALLET', (string) $palletRow[0]);
        $this->assertEquals(1, (int) $palletRow[5]);
        $this->assertEqualsWithDelta(430.0, (float) $palletRow[7], 0.01);

        // GW total = 3 caixas soltas (15) + pallet (430).
        $this->assertEqualsWithDelta(445.0, (float) $grandTotalRow[7], 0.01);
    }

    public function test_packing_list_excel_carries_cartons_and_grand_total(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $carton = Carton::create([
                'shipment_id' => $this->shipment->id,
                'label' => 'BOX-'.$i,
                'packaging_type' => 'CARTON',
                'gross_weight' => 5.0,
                'net_weight' => 4.5,
                'volume' => 0.02,
                'sort_order' => $i,
            ]);

            CartonContent::create([
                'carton_id' => $carton->id,
                'shipment_item_id' => $this->items['Pulley']->id,
                'pieces' => 2,
                'sort_order' => 1,
            ]);
        }

        $path = (new PackingListExcelExporter)->export($this->shipment->fresh());

        $this->assertFileExists($path);
        $rows = $this->readRows($path);
        $text = implode("\n", array_map(fn ($row) => implode('|', array_map(fn ($c) => (string) $c, $row)), $rows));

        $this->assertStringContainsString('PACKING LIST', $text);
        $this->assertStringContainsString('SH-XLS-1', $text);
        $this->assertStringContainsString('Pulley', $text);

        $grandTotalRow = collect($rows)->first(fn ($row) => in_array('GRAND TOTAL', array_map(fn ($c) => trim((string) $c), $row), true));
        $this->assertNotNull($grandTotalRow);

        $numbers = array_values(array_filter($grandTotalRow, fn ($c) => is_numeric($c)));
        // 3 caixas, 6 peças, 13.5kg líquido, 15kg bruto, 0.06 m³.
        $this->assertContains(3.0, array_map('floatval', $numbers));
        $this->assertContains(6.0, array_map('floatval', $numbers));
        $this->assertContains(15.0, array_map('floatval', $numbers));
        $this->assertContains(0.06, array_map('floatval', $numbers));
    }
}
