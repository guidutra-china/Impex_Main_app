<?php

namespace Tests\Feature\Logistics;

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
use ReflectionMethod;
use Tests\TestCase;

class PackingListPdfDimensionsTest extends TestCase
{
    use RefreshDatabase;

    private function makeShipmentWithItem(): array
    {
        $client = Company::create(['name' => 'Client PD-'.uniqid(), 'status' => 'active']);
        $client->companyRoles()->create(['role' => 'client']);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-PD-'.uniqid(),
            'company_id' => $client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $pi = ProformaInvoice::create([
            'reference' => 'PI-PD-'.uniqid(),
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-04-08',
            'status' => 'confirmed',
        ]);

        $shipment = Shipment::create([
            'reference' => 'SHIP-PD-'.uniqid(),
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'status' => 'draft',
            'transport_mode' => 'sea',
            'origin_port' => 'Shanghai',
            'destination_port' => 'Santos',
            'issue_date' => '2026-04-08',
        ]);

        $piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description' => 'mug',
            'quantity' => 10,
            'unit_price' => 1000,
            'unit' => 'pcs',
        ]);

        $item = ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $piItem->id,
            'quantity' => 10,
            'unit' => 'pcs',
            'sort_order' => 0,
        ]);

        return [$shipment, $item];
    }

    private function makeCarton(Shipment $shipment, ShipmentItem $item, array $attrs): void
    {
        $carton = Carton::create(array_merge([
            'shipment_id' => $shipment->id,
            'label' => 'BOX-001',
            'packaging_type' => 'CARTON',
            'sort_order' => 1,
        ], $attrs));

        CartonContent::create([
            'carton_id' => $carton->id,
            'shipment_item_id' => $item->id,
            'pieces' => 10,
            'sort_order' => 1,
        ]);
    }

    private function firstLineDimensions(Shipment $shipment): string
    {
        $template = new PackingListPdfTemplate($shipment);
        $method = new ReflectionMethod($template, 'getDocumentData');
        $method->setAccessible(true);
        $data = $method->invoke($template);

        return $data['container_groups'][0]['lines'][0]['dimensions'];
    }

    public function test_dimensions_appear_with_one_decimal_place(): void
    {
        [$shipment, $item] = $this->makeShipmentWithItem();
        $this->makeCarton($shipment, $item, [
            'length' => 50.5,
            'width' => 30.5,
            'height' => 25.5,
        ]);

        $this->assertSame('50.5 × 30.5 × 25.5', $this->firstLineDimensions($shipment));
    }

    public function test_integer_dimensions_still_show_one_decimal(): void
    {
        [$shipment, $item] = $this->makeShipmentWithItem();
        $this->makeCarton($shipment, $item, [
            'length' => 50,
            'width' => 30,
            'height' => 25,
        ]);

        $this->assertSame('50.0 × 30.0 × 25.0', $this->firstLineDimensions($shipment));
    }

    public function test_missing_dimension_shows_em_dash(): void
    {
        [$shipment, $item] = $this->makeShipmentWithItem();
        $this->makeCarton($shipment, $item, [
            'length' => 50.5,
            'width' => 30.5,
            'height' => null,
        ]);

        $this->assertSame('50.5 × 30.5 × —', $this->firstLineDimensions($shipment));
    }

    public function test_all_dimensions_null_returns_empty_string(): void
    {
        [$shipment, $item] = $this->makeShipmentWithItem();
        $this->makeCarton($shipment, $item, [
            'length' => null,
            'width' => null,
            'height' => null,
        ]);

        $this->assertSame('', $this->firstLineDimensions($shipment));
    }
}
