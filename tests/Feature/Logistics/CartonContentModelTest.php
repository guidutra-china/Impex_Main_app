<?php

namespace Tests\Feature\Logistics;

use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Models\CartonContent;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CartonContentModelTest extends TestCase
{
    use RefreshDatabase;

    private Shipment $shipment;

    private ShipmentItem $shipmentItem;

    private Carton $carton;

    protected function setUp(): void
    {
        parent::setUp();

        $client = Company::create(['name' => 'Client CCT', 'status' => 'active']);
        $client->companyRoles()->create(['role' => 'client']);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-CCT-001',
            'company_id' => $client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $pi = ProformaInvoice::create([
            'reference' => 'PI-CCT-001',
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-04-08',
            'status' => 'confirmed',
        ]);

        $piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description' => 'Test product',
            'quantity' => 100,
            'unit_price' => 1000,
            'unit' => 'pcs',
        ]);

        $this->shipment = Shipment::create([
            'reference' => 'SHIP-CCT-001',
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'status' => 'draft',
            'transport_mode' => 'sea',
            'origin_port' => 'Shanghai',
            'destination_port' => 'Santos',
        ]);

        $this->shipmentItem = ShipmentItem::create([
            'shipment_id' => $this->shipment->id,
            'proforma_invoice_item_id' => $piItem->id,
            'quantity' => 10,
            'sort_order' => 0,
        ]);

        $this->carton = Carton::create([
            'shipment_id' => $this->shipment->id,
            'label' => 'BOX-001',
            'packaging_type' => 'CARTON',
            'gross_weight' => 5.000,
        ]);
    }

    public function test_content_persists_and_belongs_to_carton(): void
    {
        $content = CartonContent::create([
            'carton_id' => $this->carton->id,
            'shipment_item_id' => $this->shipmentItem->id,
            'pieces' => 5,
        ]);

        $this->assertSame($this->carton->id, $content->carton->id);
        $this->assertSame(5, $content->pieces);
    }

    public function test_content_belongs_to_shipment_item(): void
    {
        $content = CartonContent::create([
            'carton_id' => $this->carton->id,
            'shipment_item_id' => $this->shipmentItem->id,
            'pieces' => 5,
        ]);

        $this->assertSame($this->shipmentItem->id, $content->shipmentItem->id);
    }

    public function test_content_can_have_multi_box_set_id(): void
    {
        $setId = (string) Str::ulid();

        $content = CartonContent::create([
            'carton_id' => $this->carton->id,
            'shipment_item_id' => $this->shipmentItem->id,
            'pieces' => 1,
            'part_label' => 'Frame',
            'multi_box_set_id' => $setId,
        ]);

        $this->assertSame($setId, $content->multi_box_set_id);
        $this->assertSame('Frame', $content->part_label);
    }

    public function test_content_pieces_is_cast_to_integer(): void
    {
        $content = CartonContent::create([
            'carton_id' => $this->carton->id,
            'shipment_item_id' => $this->shipmentItem->id,
            'pieces' => '7',
        ]);

        $content->refresh();
        $this->assertSame(7, $content->pieces);
    }

    public function test_weight_share_persists_with_decimal_3(): void
    {
        $content = CartonContent::create([
            'carton_id' => $this->carton->id,
            'shipment_item_id' => $this->shipmentItem->id,
            'pieces' => 5,
            'weight_share' => 2.500,
        ]);

        $content->refresh();
        $this->assertSame('2.500', (string) $content->weight_share);
    }
}
