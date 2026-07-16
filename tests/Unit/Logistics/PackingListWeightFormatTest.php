<?php

namespace Tests\Unit\Logistics;

use App\Domain\CRM\Models\Company;
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

class PackingListWeightFormatTest extends TestCase
{
    use RefreshDatabase;

    public function test_net_and_gross_weights_render_with_two_decimals(): void
    {
        $client = Company::create(['name' => 'PL Weight Client', 'status' => 'active']);
        $client->companyRoles()->create(['role' => 'client']);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-PLW-001',
            'company_id' => $client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $pi = ProformaInvoice::create([
            'reference' => 'PI-PLW-001',
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-07-01',
            'status' => 'confirmed',
        ]);

        $piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description' => 'Widget',
            'quantity' => 10,
            'unit_price' => 1000,
            'unit' => 'pcs',
        ]);

        $shipment = Shipment::create([
            'reference' => 'SHIP-PLW-001',
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'status' => 'draft',
        ]);

        $shipmentItem = ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $piItem->id,
            'quantity' => 10,
            'sort_order' => 1,
        ]);

        $carton = Carton::create([
            'shipment_id' => $shipment->id,
            'label' => 'CTN-1',
            'packaging_type' => 'CARTON',
            'net_weight' => 213.456,
            'gross_weight' => 220.5,
            'sort_order' => 1,
        ]);
        CartonContent::create([
            'carton_id' => $carton->id,
            'shipment_item_id' => $shipmentItem->id,
            'pieces' => 10,
            'sort_order' => 1,
        ]);

        $data = (new PackingListPdfTemplate($shipment, 'en'))->getData();
        $flat = json_encode($data);

        $this->assertStringContainsString('213.46', $flat, 'net weight must render with 2 decimals (rounded)');
        $this->assertStringContainsString('220.50', $flat, 'gross weight must render with 2 decimals (padded)');
        $this->assertStringNotContainsString('"213.5"', $flat, 'no 1-decimal net weight left');
        $this->assertStringNotContainsString('"220.5"', $flat, 'no 1-decimal gross weight left');
    }
}
