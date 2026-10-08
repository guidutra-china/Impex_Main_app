<?php

namespace Tests\Feature\Documents;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Pdf\Templates\CommercialInvoicePdfTemplate;
use App\Domain\Infrastructure\Pdf\Templates\PackingListPdfTemplate;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Models\CartonContent;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CI-SH-2026-00020: nomes como "LOADING AUGER FOR JOHN DEERE COMBINE:S650,
 * S660,…" (lista sem espaço) eram um token inquebrável; a coluna alargava,
 * a tabela passava da página e Qty/Preço/Total sumiam. A descrição já era
 * tratada (formatDescription); o nome não.
 */
class LongProductNameWrapsTest extends TestCase
{
    use RefreshDatabase;

    private Shipment $shipment;

    protected function setUp(): void
    {
        parent::setUp();

        $client = Company::create(['name' => 'Marangatu', 'status' => 'active']);
        $client->companyRoles()->create(['role' => 'client']);

        $inquiry = Inquiry::create(['reference' => 'INQ-W-1', 'company_id' => $client->id, 'status' => 'received', 'source' => 'email', 'currency_code' => 'USD']);
        $pi = ProformaInvoice::create(['reference' => 'PI-W-1', 'inquiry_id' => $inquiry->id, 'company_id' => $client->id, 'currency_code' => 'USD', 'issue_date' => '2026-10-01', 'status' => 'confirmed']);
        $this->shipment = Shipment::create(['reference' => 'SH-W-1', 'company_id' => $client->id, 'currency_code' => 'USD', 'status' => 'draft', 'transport_mode' => 'sea', 'issue_date' => '2026-10-01']);

        foreach ([
            'LOADING AUGER FOR JOHN DEERE COMBINE:S650,S660,S670,S685,S690,S760,S770,S780,S785,S790',
            'Halter 12,5 kg',
        ] as $i => $name) {
            $product = Product::factory()->create(['name' => $name]);
            $piItem = ProformaInvoiceItem::create([
                'proforma_invoice_id' => $pi->id, 'product_id' => $product->id, 'description' => $name,
                'quantity' => 2, 'unit_price' => 100000, 'unit' => 'pcs', 'sort_order' => $i,
            ]);
            $shipmentItem = ShipmentItem::create(['shipment_id' => $this->shipment->id, 'proforma_invoice_item_id' => $piItem->id, 'quantity' => 2, 'unit' => 'pcs', 'sort_order' => $i]);

            // O Packing List só lista caixas embaladas: uma caixa por item.
            $carton = Carton::create([
                'shipment_id' => $this->shipment->id, 'label' => 'BOX-'.$i, 'packaging_type' => 'CARTON',
                'gross_weight' => 5.0, 'net_weight' => 4.5, 'volume' => 0.02, 'sort_order' => $i,
            ]);
            CartonContent::create(['carton_id' => $carton->id, 'shipment_item_id' => $shipmentItem->id, 'pieces' => 2, 'sort_order' => 1]);
        }
    }

    public function test_commercial_invoice_makes_long_model_lists_breakable_and_leaves_short_names_alone(): void
    {
        $items = (new CommercialInvoicePdfTemplate($this->shipment->fresh(), 'en'))->getData()['items'];

        $this->assertSame(
            'LOADING AUGER FOR JOHN DEERE COMBINE: S650, S660, S670, S685, S690, S760, S770, S780, S785, S790',
            $items[0]['product_name'],
        );
        $this->assertSame('Halter 12,5 kg', $items[1]['product_name']);

        // Nenhum token do nome passa do que cabe na coluna.
        foreach ($items as $item) {
            foreach (preg_split('/\s+/', $item['product_name']) as $token) {
                $this->assertLessThan(25, strlen($token), $token);
            }
        }
    }

    public function test_packing_list_applies_the_same_rule(): void
    {
        $lines = collect((new PackingListPdfTemplate($this->shipment->fresh(), 'en'))->getData()['container_groups'])
            ->flatMap(fn ($group) => $group['lines'])
            ->pluck('product_name')
            ->all();

        $this->assertContains('LOADING AUGER FOR JOHN DEERE COMBINE: S650, S660, S670, S685, S690, S760, S770, S780, S785, S790', $lines);
        $this->assertContains('Halter 12,5 kg', $lines);
    }
}
