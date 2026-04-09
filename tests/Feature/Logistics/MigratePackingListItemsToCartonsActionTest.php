<?php

namespace Tests\Feature\Logistics;

use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Actions\MigratePackingListItemsToCartonsAction;
use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Models\CartonContent;
use App\Domain\Logistics\Models\PackingListItem;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MigratePackingListItemsToCartonsActionTest extends TestCase
{
    use RefreshDatabase;

    private MigratePackingListItemsToCartonsAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = app(MigratePackingListItemsToCartonsAction::class);
    }

    private function makeShipmentWithItem(int $quantity = 100): array
    {
        $client = Company::create(['name' => 'Client M'.uniqid(), 'status' => 'active']);
        $client->companyRoles()->create(['role' => 'client']);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-M-'.uniqid(),
            'company_id' => $client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $pi = ProformaInvoice::create([
            'reference' => 'PI-M-'.uniqid(),
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-04-08',
            'status' => 'confirmed',
        ]);

        $piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description' => 'Test product',
            'quantity' => $quantity,
            'unit_price' => 1000,
            'unit' => 'pcs',
        ]);

        $shipment = Shipment::create([
            'reference' => 'SHIP-M-'.uniqid(),
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
            'quantity' => $quantity,
            'sort_order' => 0,
        ]);

        return [$shipment, $shipmentItem];
    }

    public function test_simple_legacy_creates_one_carton_per_carton_number(): void
    {
        [$shipment, $shipmentItem] = $this->makeShipmentWithItem(100);

        // Legacy: 100 sandals at 10/carton = 10 cartons (range 1-10)
        PackingListItem::create([
            'shipment_id' => $shipment->id,
            'shipment_item_id' => $shipmentItem->id,
            'carton_number' => '1-10',
            'carton_from' => 1,
            'carton_to' => 10,
            'quantity' => 10,
            'qty_per_carton' => 10,
            'total_quantity' => 100,
            'gross_weight' => 5.000,
            'net_weight' => 4.500,
            'length' => 50.00,
            'width' => 40.00,
            'height' => 30.00,
            'volume' => 0.0600,
        ]);

        $this->action->execute($shipment);

        // 10 cartons created
        $this->assertSame(10, Carton::where('shipment_id', $shipment->id)->count());

        // BOX-001 has the right values
        $box1 = Carton::where('shipment_id', $shipment->id)->where('label', 'BOX-001')->first();
        $this->assertNotNull($box1);
        $this->assertSame('5.000', (string) $box1->gross_weight);
        $this->assertCount(1, $box1->contents);
        $this->assertSame(10, $box1->contents->first()->pieces);
        $this->assertNull($box1->contents->first()->multi_box_set_id);

        // BOX-010 also exists
        $box10 = Carton::where('shipment_id', $shipment->id)->where('label', 'BOX-010')->first();
        $this->assertNotNull($box10);
    }

    public function test_legacy_mixed_mode_creates_one_carton_with_multiple_contents(): void
    {
        [$shipment, $shipmentItem] = $this->makeShipmentWithItem(50);

        // Second product on the same shipment, sharing the same proforma_invoice
        $piItem2 = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $shipmentItem->proformaInvoiceItem->proforma_invoice_id,
            'description' => 'Second product',
            'quantity' => 30,
            'unit_price' => 500,
            'unit' => 'pcs',
        ]);
        $shipmentItem2 = ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $piItem2->id,
            'quantity' => 30,
            'sort_order' => 1,
        ]);

        // Legacy mixed mode: both items share carton range 1-3
        PackingListItem::create([
            'shipment_id' => $shipment->id,
            'shipment_item_id' => $shipmentItem->id,
            'carton_number' => '1-3',
            'carton_from' => 1,
            'carton_to' => 3,
            'quantity' => 3,
            'qty_per_carton' => 10,
            'total_quantity' => 30,
            'gross_weight' => 6.000,
        ]);
        PackingListItem::create([
            'shipment_id' => $shipment->id,
            'shipment_item_id' => $shipmentItem2->id,
            'carton_number' => '1-3',
            'carton_from' => 1,
            'carton_to' => 3,
            'quantity' => 3,
            'qty_per_carton' => 5,
            'total_quantity' => 15,
            'gross_weight' => 6.000,
        ]);

        $this->action->execute($shipment);

        // Only 3 cartons (BOX-001, 002, 003), not 6
        $this->assertSame(3, Carton::where('shipment_id', $shipment->id)->count());

        // BOX-001 has 2 contents: one for each shipment_item
        $box1 = Carton::where('shipment_id', $shipment->id)->where('label', 'BOX-001')->first();
        $this->assertCount(2, $box1->contents);

        $piecesByItem = $box1->contents->pluck('pieces', 'shipment_item_id')->toArray();
        $this->assertSame(10, $piecesByItem[$shipmentItem->id]);
        $this->assertSame(5, $piecesByItem[$shipmentItem2->id]);

        // No multi_box_set_id for either content (mixed mode is not multi-box)
        $this->assertTrue($box1->contents->every(fn ($c) => $c->multi_box_set_id === null));
    }

    public function test_legacy_multi_box_creates_distinct_set_per_unit(): void
    {
        [$shipment, $shipmentItem] = $this->makeShipmentWithItem(2);

        // Legacy multi-box: 2 machines, each in 2 cartons (Frame + Accessories)
        // Frame items occupy cartons 1-2 (one per machine), is_primary_package=true
        PackingListItem::create([
            'shipment_id' => $shipment->id,
            'shipment_item_id' => $shipmentItem->id,
            'carton_number' => '1-2',
            'carton_from' => 1,
            'carton_to' => 2,
            'quantity' => 2,
            'qty_per_carton' => 1,
            'total_quantity' => 2,
            'gross_weight' => 50.000,
            'package_label' => 'Frame',
            'is_primary_package' => true,
        ]);
        // Accessories items occupy cartons 3-4 (one per machine), is_primary_package=false
        PackingListItem::create([
            'shipment_id' => $shipment->id,
            'shipment_item_id' => $shipmentItem->id,
            'carton_number' => '3-4',
            'carton_from' => 3,
            'carton_to' => 4,
            'quantity' => 2,
            'qty_per_carton' => 1,
            'total_quantity' => 2,
            'gross_weight' => 15.000,
            'package_label' => 'Accessories',
            'is_primary_package' => false,
        ]);

        $this->action->execute($shipment);

        // 4 cartons total
        $this->assertSame(4, Carton::where('shipment_id', $shipment->id)->count());

        // 4 contents
        $contents = CartonContent::whereHas('carton', fn ($q) => $q->where('shipment_id', $shipment->id))
            ->get();
        $this->assertCount(4, $contents);

        // 2 distinct multi_box_set_ids — one per physical machine
        $setIds = $contents->pluck('multi_box_set_id')->unique()->values();
        $this->assertCount(2, $setIds, 'Should create 2 distinct set IDs (one per physical unit)');
        $this->assertTrue($setIds->every(fn ($id) => $id !== null && strlen($id) === 26));

        // Positional pairing: BOX-001's Frame and BOX-003's Accessories share a set ID
        $cartonsByLabel = Carton::where('shipment_id', $shipment->id)
            ->orderBy('label')
            ->get()
            ->keyBy('label');

        $box1Content = $cartonsByLabel['BOX-001']->contents->first();
        $box2Content = $cartonsByLabel['BOX-002']->contents->first();
        $box3Content = $cartonsByLabel['BOX-003']->contents->first();
        $box4Content = $cartonsByLabel['BOX-004']->contents->first();

        $this->assertSame('Frame', $box1Content->part_label);
        $this->assertSame('Frame', $box2Content->part_label);
        $this->assertSame('Accessories', $box3Content->part_label);
        $this->assertSame('Accessories', $box4Content->part_label);

        // BOX-001 (1st Frame) ↔ BOX-003 (1st Accessories)
        $this->assertSame(
            $box1Content->multi_box_set_id,
            $box3Content->multi_box_set_id,
            'First Frame should share set with first Accessories'
        );

        // BOX-002 (2nd Frame) ↔ BOX-004 (2nd Accessories)
        $this->assertSame(
            $box2Content->multi_box_set_id,
            $box4Content->multi_box_set_id,
            'Second Frame should share set with second Accessories'
        );

        // The two units have DIFFERENT set IDs
        $this->assertNotSame(
            $box1Content->multi_box_set_id,
            $box2Content->multi_box_set_id,
            'Each physical unit gets its own set ID'
        );
    }

    public function test_running_twice_does_not_duplicate_cartons_or_contents(): void
    {
        [$shipment, $shipmentItem] = $this->makeShipmentWithItem(20);

        PackingListItem::create([
            'shipment_id' => $shipment->id,
            'shipment_item_id' => $shipmentItem->id,
            'carton_number' => '1-2',
            'carton_from' => 1,
            'carton_to' => 2,
            'quantity' => 2,
            'qty_per_carton' => 10,
            'total_quantity' => 20,
            'gross_weight' => 8.000,
        ]);

        $this->action->execute($shipment);
        $this->action->execute($shipment);

        $this->assertSame(2, Carton::where('shipment_id', $shipment->id)->count());

        $contentsCount = CartonContent::whereHas('carton', fn ($q) => $q->where('shipment_id', $shipment->id))
            ->count();
        $this->assertSame(2, $contentsCount, 'Each carton should still have exactly 1 content');
    }

    public function test_carton_totals_match_legacy_packing_list_totals(): void
    {
        [$shipment, $shipmentItem] = $this->makeShipmentWithItem(50);

        PackingListItem::create([
            'shipment_id' => $shipment->id,
            'shipment_item_id' => $shipmentItem->id,
            'carton_number' => '1-5',
            'carton_from' => 1,
            'carton_to' => 5,
            'quantity' => 5,
            'qty_per_carton' => 10,
            'total_quantity' => 50,
            'gross_weight' => 4.000,
            'net_weight' => 3.500,
            'volume' => 0.0500,
        ]);

        $this->action->execute($shipment);

        $cartonTotals = $shipment->cartons()
            ->selectRaw('
                COUNT(*) as total_packages,
                SUM(gross_weight) as total_gross,
                SUM(net_weight) as total_net,
                SUM(volume) as total_vol
            ')
            ->first();

        $this->assertSame(5, (int) $cartonTotals->total_packages);
        $this->assertSame('20.000', number_format((float) $cartonTotals->total_gross, 3, '.', ''));
        $this->assertSame('17.500', number_format((float) $cartonTotals->total_net, 3, '.', ''));
        $this->assertSame('0.2500', number_format((float) $cartonTotals->total_vol, 4, '.', ''));
    }
}
