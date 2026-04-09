<?php

namespace Tests\Feature\Logistics;

use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Models\CartonContent;
use App\Domain\Logistics\Models\PackingListItem;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PackingListItemObserverTest extends TestCase
{
    use RefreshDatabase;

    private function makeShipmentWithItem(int $qty = 100): array
    {
        $client = Company::create(['name' => 'Client Obs-'.uniqid(), 'status' => 'active']);
        $client->companyRoles()->create(['role' => 'client']);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-OBS-'.uniqid(),
            'company_id' => $client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $pi = ProformaInvoice::create([
            'reference' => 'PI-OBS-'.uniqid(),
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-04-08',
            'status' => 'confirmed',
        ]);

        $piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description' => 'Product',
            'quantity' => $qty,
            'unit_price' => 1000,
            'unit' => 'pcs',
        ]);

        $shipment = Shipment::create([
            'reference' => 'SHIP-OBS-'.uniqid(),
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'status' => 'draft',
            'transport_mode' => 'sea',
            'origin_port' => 'Shanghai',
            'destination_port' => 'Santos',
        ]);

        $shipmentItem = ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $piItem->id,
            'quantity' => $qty,
            'sort_order' => 0,
        ]);

        return [$shipment, $shipmentItem];
    }

    public function test_creating_legacy_item_creates_cartons(): void
    {
        [$shipment, $shipmentItem] = $this->makeShipmentWithItem(100);

        $this->assertEquals(0, $shipment->cartons()->count());

        PackingListItem::create([
            'shipment_id' => $shipment->id,
            'shipment_item_id' => $shipmentItem->id,
            'carton_from' => 1,
            'carton_to' => 10,
            'quantity' => 10,
            'qty_per_carton' => 10,
            'total_quantity' => 100,
            'gross_weight' => 5.0,
            'net_weight' => 4.5,
            'total_gross_weight' => 50.0,
            'total_net_weight' => 45.0,
            'volume' => 0.020,
            'total_volume' => 0.200,
            'sort_order' => 0,
            'packaging_type' => 'carton',
            'is_primary_package' => true,
        ]);

        $shipment->refresh();
        $this->assertEquals(10, $shipment->cartons()->count());
        $this->assertEquals(10, CartonContent::whereIn('carton_id', $shipment->cartons()->pluck('id'))->count());
    }

    public function test_updating_legacy_item_rebuilds_cartons(): void
    {
        [$shipment, $shipmentItem] = $this->makeShipmentWithItem(100);

        $item = PackingListItem::create([
            'shipment_id' => $shipment->id,
            'shipment_item_id' => $shipmentItem->id,
            'carton_from' => 1,
            'carton_to' => 5,
            'quantity' => 5,
            'qty_per_carton' => 20,
            'total_quantity' => 100,
            'gross_weight' => 10.0,
            'sort_order' => 0,
            'packaging_type' => 'carton',
            'is_primary_package' => true,
        ]);

        $this->assertEquals(5, $shipment->cartons()->count());

        // Update — expand range to 10 cartons
        $item->update([
            'carton_to' => 10,
            'quantity' => 10,
            'qty_per_carton' => 10,
        ]);

        $shipment->refresh();
        $this->assertEquals(10, $shipment->cartons()->count());
    }

    public function test_deleting_legacy_item_removes_cartons(): void
    {
        [$shipment, $shipmentItem] = $this->makeShipmentWithItem(100);

        $item = PackingListItem::create([
            'shipment_id' => $shipment->id,
            'shipment_item_id' => $shipmentItem->id,
            'carton_from' => 1,
            'carton_to' => 10,
            'quantity' => 10,
            'qty_per_carton' => 10,
            'total_quantity' => 100,
            'gross_weight' => 5.0,
            'sort_order' => 0,
            'packaging_type' => 'carton',
            'is_primary_package' => true,
        ]);

        $this->assertEquals(10, $shipment->cartons()->count());

        $item->delete();

        $shipment->refresh();
        $this->assertEquals(0, $shipment->cartons()->count());
    }

    public function test_multi_box_siblings_get_matching_set_ids(): void
    {
        [$shipment, $shipmentItem] = $this->makeShipmentWithItem(1);

        // Primary part
        PackingListItem::create([
            'shipment_id' => $shipment->id,
            'shipment_item_id' => $shipmentItem->id,
            'carton_from' => 1,
            'carton_to' => 1,
            'quantity' => 1,
            'qty_per_carton' => 1,
            'total_quantity' => 1,
            'gross_weight' => 50.0,
            'package_label' => 'Frame',
            'is_primary_package' => true,
            'packaging_type' => 'carton',
            'sort_order' => 0,
        ]);

        // Non-primary sibling
        PackingListItem::create([
            'shipment_id' => $shipment->id,
            'shipment_item_id' => $shipmentItem->id,
            'carton_from' => 2,
            'carton_to' => 2,
            'quantity' => 1,
            'qty_per_carton' => 1,
            'total_quantity' => 1,
            'gross_weight' => 15.0,
            'package_label' => 'Accessories',
            'is_primary_package' => false,
            'packaging_type' => 'carton',
            'sort_order' => 1,
        ]);

        $shipment->refresh();

        $this->assertEquals(2, $shipment->cartons()->count());

        // Both contents should share the same multi_box_set_id
        $contents = CartonContent::whereIn('carton_id', $shipment->cartons()->pluck('id'))->get();
        $this->assertCount(2, $contents);

        $setIds = $contents->pluck('multi_box_set_id')->filter()->unique();
        $this->assertCount(1, $setIds, 'Both siblings must share a single multi_box_set_id');
    }

    public function test_direct_carton_writes_do_not_trigger_observer_loop(): void
    {
        [$shipment] = $this->makeShipmentWithItem(10);

        // Directly create a carton (not via legacy UI)
        Carton::create([
            'shipment_id' => $shipment->id,
            'label' => 'BOX-999',
            'packaging_type' => 'carton',
        ]);

        // No legacy items exist, so after direct write, shipment should still have that 1 carton
        // (observer doesn't fire since no PackingListItem was touched)
        $this->assertEquals(1, $shipment->cartons()->count());
        $this->assertEquals('BOX-999', $shipment->cartons()->first()->label);
    }
}
