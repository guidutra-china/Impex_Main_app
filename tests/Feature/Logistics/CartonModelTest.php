<?php

namespace Tests\Feature\Logistics;

use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Models\CartonContent;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartonModelTest extends TestCase
{
    use RefreshDatabase;

    private Shipment $shipment;

    protected function setUp(): void
    {
        parent::setUp();

        $client = Company::create(['name' => 'Client CT', 'status' => 'active']);
        $client->companyRoles()->create(['role' => 'client']);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-CT-001',
            'company_id' => $client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $pi = ProformaInvoice::create([
            'reference' => 'PI-CT-001',
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-04-08',
            'status' => 'confirmed',
        ]);

        $this->shipment = Shipment::create([
            'reference' => 'SHIP-CT-001',
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'status' => 'draft',
            'transport_mode' => 'sea',
            'origin_port' => 'Shanghai',
            'destination_port' => 'Santos',
        ]);
    }

    public function test_carton_persists_with_required_fields(): void
    {
        $carton = Carton::create([
            'shipment_id' => $this->shipment->id,
            'label' => 'BOX-001',
            'packaging_type' => 'CARTON',
            'gross_weight' => 12.500,
            'net_weight' => 11.250,
            'length' => 50.00,
            'width' => 40.00,
            'height' => 30.00,
            'volume' => 0.0600,
        ]);

        $this->assertDatabaseHas('cartons', [
            'id' => $carton->id,
            'label' => 'BOX-001',
            'shipment_id' => $this->shipment->id,
        ]);
        $this->assertSame('12.500', (string) $carton->gross_weight);
    }

    public function test_carton_belongs_to_shipment(): void
    {
        $carton = Carton::create([
            'shipment_id' => $this->shipment->id,
            'label' => 'BOX-001',
            'packaging_type' => 'CARTON',
        ]);

        $this->assertSame($this->shipment->id, $carton->shipment->id);
    }

    public function test_carton_has_many_contents(): void
    {
        $carton = Carton::create([
            'shipment_id' => $this->shipment->id,
            'label' => 'BOX-001',
            'packaging_type' => 'CARTON',
        ]);

        CartonContent::create([
            'carton_id' => $carton->id,
            'shipment_item_id' => null,
            'pieces' => 5,
        ]);
        CartonContent::create([
            'carton_id' => $carton->id,
            'shipment_item_id' => null,
            'pieces' => 3,
        ]);

        $this->assertCount(2, $carton->contents);
    }

    public function test_label_is_unique_per_shipment(): void
    {
        Carton::create([
            'shipment_id' => $this->shipment->id,
            'label' => 'BOX-001',
            'packaging_type' => 'CARTON',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Carton::create([
            'shipment_id' => $this->shipment->id,
            'label' => 'BOX-001',
            'packaging_type' => 'CARTON',
        ]);
    }

    public function test_shipment_has_many_cartons(): void
    {
        Carton::create([
            'shipment_id' => $this->shipment->id,
            'label' => 'BOX-001',
            'packaging_type' => 'CARTON',
        ]);
        Carton::create([
            'shipment_id' => $this->shipment->id,
            'label' => 'BOX-002',
            'packaging_type' => 'CARTON',
        ]);

        $this->assertCount(2, $this->shipment->cartons);
    }
}
