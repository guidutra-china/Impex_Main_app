<?php

namespace Tests\Feature\Livewire\Logistics;

use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Models\CartonContent;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Livewire\Logistics\PackingListBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PackingListBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function makeShipment(array $itemQuantities = ['product' => 10]): array
    {
        $client = Company::create(['name' => 'Client PLB-'.uniqid(), 'status' => 'active']);
        $client->companyRoles()->create(['role' => 'client']);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-PLB-'.uniqid(),
            'company_id' => $client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $pi = ProformaInvoice::create([
            'reference' => 'PI-PLB-'.uniqid(),
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-04-08',
            'status' => 'confirmed',
        ]);

        $shipment = Shipment::create([
            'reference' => 'SHIP-PLB-'.uniqid(),
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'status' => 'draft',
            'transport_mode' => 'sea',
            'origin_port' => 'Shanghai',
            'destination_port' => 'Santos',
        ]);

        $items = [];
        foreach ($itemQuantities as $name => $qty) {
            $piItem = ProformaInvoiceItem::create([
                'proforma_invoice_id' => $pi->id,
                'description' => $name,
                'quantity' => $qty,
                'unit_price' => 1000,
                'unit' => 'pcs',
            ]);

            $items[$name] = ShipmentItem::create([
                'shipment_id' => $shipment->id,
                'proforma_invoice_item_id' => $piItem->id,
                'quantity' => $qty,
                'sort_order' => count($items),
            ]);
        }

        return [$shipment, $items];
    }

    public function test_mount_loads_shipment(): void
    {
        [$shipment] = $this->makeShipment();

        Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->assertOk()
            ->assertSet('shipment.id', $shipment->id);
    }

    public function test_create_carton_creates_row(): void
    {
        [$shipment] = $this->makeShipment();

        Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->call('createCarton')
            ->assertOk();

        $this->assertEquals(1, $shipment->cartons()->count());
        $this->assertEquals('BOX-001', $shipment->cartons()->first()->label);
    }

    public function test_delete_carton_removes_row(): void
    {
        [$shipment] = $this->makeShipment();

        $component = Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->call('createCarton');

        $carton = $shipment->cartons()->first();
        $component->call('deleteCarton', $carton->id);

        $this->assertEquals(0, $shipment->cartons()->count());
    }

    public function test_confirm_add_content_for_non_split_item(): void
    {
        [$shipment, $items] = $this->makeShipment(['p' => 10]);

        $component = Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->call('createCarton');

        $cartonId = $shipment->cartons()->first()->id;

        $component->call('startAddContent', $cartonId)
            ->set('addContentItemId', $items['p']->id)
            ->set('addContentPieces', 5)
            ->call('confirmAddContent');

        $this->assertEquals(1, CartonContent::where('carton_id', $cartonId)->count());
        $this->assertEquals(5, CartonContent::where('carton_id', $cartonId)->first()->pieces);
    }

    public function test_confirm_add_content_rejects_split_items(): void
    {
        [$shipment, $items] = $this->makeShipment(['machine' => 4]);

        // Pre-split the item
        $items['machine']->update([
            'packing_split' => [
                'set_id' => '01HTESTSPLIT000000000000000',
                'part_labels' => ['Frame', 'Accessories'],
            ],
        ]);

        $component = Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->call('createCarton');

        $cartonId = $shipment->cartons()->first()->id;

        $component->call('startAddContent', $cartonId)
            ->set('addContentItemId', $items['machine']->id)
            ->set('addContentPieces', 4)
            ->call('confirmAddContent');

        $this->assertEquals(0, CartonContent::where('carton_id', $cartonId)->count());
    }

    public function test_delete_content_removes_row(): void
    {
        [$shipment, $items] = $this->makeShipment(['p' => 10]);

        $component = Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->call('createCarton');

        $cartonId = $shipment->cartons()->first()->id;

        $component->call('startAddContent', $cartonId)
            ->set('addContentItemId', $items['p']->id)
            ->set('addContentPieces', 5)
            ->call('confirmAddContent');

        $contentId = CartonContent::where('carton_id', $cartonId)->first()->id;

        $component->call('deleteContent', $contentId);

        $this->assertEquals(0, CartonContent::where('carton_id', $cartonId)->count());
    }

    public function test_confirm_split_persists_definition_on_item(): void
    {
        [$shipment, $items] = $this->makeShipment(['machine' => 4]);

        $component = Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->call('startSplit', $items['machine']->id)
            ->set('splitPartLabels', ['Frame', 'Accessories'])
            ->call('confirmSplit');

        $items['machine']->refresh();
        $this->assertNotNull($items['machine']->packing_split);
        $this->assertEquals(['Frame', 'Accessories'], $items['machine']->packing_split['part_labels']);
        $this->assertEquals(0, CartonContent::count(), 'Split must not create carton contents directly');
    }

    public function test_place_part_pieces_creates_content_with_set_id(): void
    {
        [$shipment, $items] = $this->makeShipment(['machine' => 4]);

        $component = Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->call('createCarton')  // BOX-001
            ->call('createCarton')  // BOX-002
            ->call('createCarton'); // BOX-003

        $cartons = $shipment->cartons()->orderBy('label')->get();

        $component->call('startSplit', $items['machine']->id)
            ->set('splitPartLabels', ['Frame', 'Accessories'])
            ->call('confirmSplit');

        $items['machine']->refresh();
        $setId = $items['machine']->packing_split['set_id'];
        $key = $items['machine']->id.'::Frame';

        // Place 4 Frames in box 1
        $component->set("placeForm.{$key}.cartonId", $cartons[0]->id)
            ->set("placeForm.{$key}.pieces", 4)
            ->call('placePartPieces', $items['machine']->id, 'Frame');

        $content = CartonContent::where('carton_id', $cartons[0]->id)->first();
        $this->assertNotNull($content);
        $this->assertEquals($setId, $content->multi_box_set_id);
        $this->assertEquals('Frame', $content->part_label);
        $this->assertEquals(4, $content->pieces);
    }

    public function test_distributing_accessories_across_multiple_boxes(): void
    {
        [$shipment, $items] = $this->makeShipment(['machine' => 4]);

        $component = Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->call('createCarton')  // BOX-001
            ->call('createCarton')  // BOX-002
            ->call('createCarton'); // BOX-003

        $cartons = $shipment->cartons()->orderBy('label')->get();

        $component->call('startSplit', $items['machine']->id)
            ->set('splitPartLabels', ['Frame', 'Accessories'])
            ->call('confirmSplit');

        $items['machine']->refresh();

        // User's exact scenario: 4 Frames in box 01, 3 Accessories in box 02, 1 in box 03
        $frameKey = $items['machine']->id.'::Frame';
        $accKey = $items['machine']->id.'::Accessories';

        $component->set("placeForm.{$frameKey}.cartonId", $cartons[0]->id)
            ->set("placeForm.{$frameKey}.pieces", 4)
            ->call('placePartPieces', $items['machine']->id, 'Frame');

        $component->set("placeForm.{$accKey}.cartonId", $cartons[1]->id)
            ->set("placeForm.{$accKey}.pieces", 3)
            ->call('placePartPieces', $items['machine']->id, 'Accessories');

        $component->set("placeForm.{$accKey}.cartonId", $cartons[2]->id)
            ->set("placeForm.{$accKey}.pieces", 1)
            ->call('placePartPieces', $items['machine']->id, 'Accessories');

        // Now verify product is complete: min(Frame:4, Accessories:4) = 4
        $progress = app(\App\Domain\Logistics\Services\PackingProgressService::class)
            ->forShipmentItem($items['machine']->fresh());

        $this->assertEquals(4, $progress->packedComplete);
        $this->assertEquals(0, $progress->remaining());
    }

    public function test_place_part_pieces_rejects_overshoot(): void
    {
        [$shipment, $items] = $this->makeShipment(['machine' => 4]);

        $component = Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->call('createCarton');

        $cartonId = $shipment->cartons()->first()->id;

        $component->call('startSplit', $items['machine']->id)
            ->set('splitPartLabels', ['Frame', 'Accessories'])
            ->call('confirmSplit');

        $items['machine']->refresh();
        $key = $items['machine']->id.'::Frame';

        // Try to place 5 Frames when target is 4 — should fail gracefully
        $component->set("placeForm.{$key}.cartonId", $cartonId)
            ->set("placeForm.{$key}.pieces", 5)
            ->call('placePartPieces', $items['machine']->id, 'Frame')
            ->assertOk();

        $this->assertEquals(0, CartonContent::count(), 'Overshoot should be rejected');
    }

    public function test_clear_split_removes_definition_and_contents(): void
    {
        [$shipment, $items] = $this->makeShipment(['machine' => 4]);

        $component = Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->call('createCarton');

        $cartonId = $shipment->cartons()->first()->id;

        $component->call('startSplit', $items['machine']->id)
            ->set('splitPartLabels', ['Frame', 'Accessories'])
            ->call('confirmSplit');

        $items['machine']->refresh();
        $key = $items['machine']->id.'::Frame';

        $component->set("placeForm.{$key}.cartonId", $cartonId)
            ->set("placeForm.{$key}.pieces", 2)
            ->call('placePartPieces', $items['machine']->id, 'Frame');

        $this->assertEquals(1, CartonContent::count());

        $component->call('clearSplit', $items['machine']->id);

        $items['machine']->refresh();
        $this->assertNull($items['machine']->packing_split);
        $this->assertEquals(0, CartonContent::count());
    }

    public function test_updated_split_parts_count_resizes_labels(): void
    {
        [$shipment, $items] = $this->makeShipment(['p' => 1]);

        $component = Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->call('startSplit', $items['p']->id);

        $component->set('splitPartsCount', 4);
        $this->assertCount(4, $component->get('splitPartLabels'));

        $component->set('splitPartsCount', 2);
        $this->assertCount(2, $component->get('splitPartLabels'));
    }

    public function test_generate_from_items_creates_cartons(): void
    {
        [$shipment] = $this->makeShipment(['p' => 5]);

        Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->call('generateFromItems', false);

        $this->assertGreaterThan(0, $shipment->cartons()->count());
    }

    public function test_start_edit_carton_populates_form(): void
    {
        [$shipment] = $this->makeShipment();

        $component = Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->call('createCarton');

        $carton = $shipment->cartons()->first();
        $carton->update([
            'gross_weight' => 12.5,
            'length' => 50,
            'width' => 40,
            'height' => 30,
        ]);

        $component->call('startEditCarton', $carton->id)
            ->assertSet('editCartonId', $carton->id)
            ->assertSet('editCartonForm.gross_weight', '12.500')
            ->assertSet('editCartonForm.length', '50.00');
    }

    public function test_save_edit_carton_persists_dimensions_and_auto_computes(): void
    {
        [$shipment] = $this->makeShipment();

        $component = Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->call('createCarton');

        $carton = $shipment->cartons()->first();

        $component->call('startEditCarton', $carton->id)
            ->set('editCartonForm.gross_weight', 20.0)
            ->set('editCartonForm.length', 60)
            ->set('editCartonForm.width', 40)
            ->set('editCartonForm.height', 30)
            ->set('editCartonForm.container_number', 'CCLU1234567')
            ->set('editCartonForm.pallet_number', 3)
            ->call('saveEditCarton');

        $carton->refresh();
        $this->assertEquals('20.000', $carton->gross_weight);
        $this->assertEquals('18.000', $carton->net_weight); // auto: 90% of gross
        $this->assertEquals('0.0720', $carton->volume); // auto: 60*40*30 / 1e6
        $this->assertEquals('CCLU1234567', $carton->container_number);
        $this->assertEquals(3, $carton->pallet_number);
    }

    public function test_cancel_edit_carton_discards_changes(): void
    {
        [$shipment] = $this->makeShipment();

        $component = Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->call('createCarton');

        $carton = $shipment->cartons()->first();

        $component->call('startEditCarton', $carton->id)
            ->set('editCartonForm.gross_weight', 999)
            ->call('cancelEditCarton')
            ->assertSet('editCartonId', null);

        $carton->refresh();
        $this->assertNull($carton->gross_weight);
    }

    // ---------- Container / Pallet hierarchy ----------

    public function test_create_container_adds_to_shipment(): void
    {
        [$shipment] = $this->makeShipment();

        Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->call('createContainer');

        $this->assertEquals(1, $shipment->shipmentContainers()->count());
        $this->assertEquals('CONT-001', $shipment->shipmentContainers()->first()->label);
    }

    public function test_save_edit_container_persists_fields(): void
    {
        [$shipment] = $this->makeShipment();

        $component = Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->call('createContainer');

        $container = $shipment->shipmentContainers()->first();

        $component->call('startEditContainer', $container->id)
            ->set('editContainerForm.container_number', 'CCLU7730065')
            ->set('editContainerForm.type', '40HQ')
            ->set('editContainerForm.seal_number', 'CN12345')
            ->call('saveEditContainer');

        $container->refresh();
        $this->assertEquals('CCLU7730065', $container->container_number);
        $this->assertEquals('40HQ', $container->type);
        $this->assertEquals('CN12345', $container->seal_number);
    }

    public function test_create_pallet_inside_container_via_button(): void
    {
        [$shipment] = $this->makeShipment();

        $component = Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->call('createContainer');

        $container = $shipment->shipmentContainers()->first();

        $component->call('createPallet', $container->id);

        $pallet = $shipment->shipmentPallets()->first();
        $this->assertNotNull($pallet);
        $this->assertEquals($container->id, $pallet->shipment_container_id);
    }

    public function test_create_carton_nested_in_pallet(): void
    {
        [$shipment] = $this->makeShipment();

        $component = Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->call('createContainer');

        $container = $shipment->shipmentContainers()->first();
        $component->call('createPallet', $container->id);

        $pallet = $shipment->shipmentPallets()->first();
        $component->call('createCarton', $container->id, $pallet->id);

        $carton = $shipment->cartons()->first();
        $this->assertEquals($container->id, $carton->shipment_container_id);
        $this->assertEquals($pallet->id, $carton->shipment_pallet_id);
    }

    public function test_move_carton_to_pallet_inherits_container(): void
    {
        [$shipment] = $this->makeShipment();

        $component = Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->call('createCarton')     // loose
            ->call('createContainer')
            ->call('createPallet', $shipment->shipmentContainers()->first()->id);

        $carton = $shipment->cartons()->first();
        $container = $shipment->shipmentContainers()->first();
        $pallet = $shipment->shipmentPallets()->first();

        $component->call('startMoveCarton', $carton->id)
            ->call('moveCartonTo', 'pallet:'.$pallet->id);

        $carton->refresh();
        $this->assertEquals($pallet->id, $carton->shipment_pallet_id);
        $this->assertEquals($container->id, $carton->shipment_container_id);
    }

    public function test_move_carton_to_loose(): void
    {
        [$shipment] = $this->makeShipment();

        $component = Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->call('createContainer');
        $container = $shipment->shipmentContainers()->first();
        $component->call('createCarton', $container->id);

        $carton = $shipment->cartons()->first();
        $this->assertEquals($container->id, $carton->shipment_container_id);

        $component->call('startMoveCarton', $carton->id)
            ->call('moveCartonTo', 'loose');

        $carton->refresh();
        $this->assertNull($carton->shipment_container_id);
        $this->assertNull($carton->shipment_pallet_id);
    }

    public function test_move_loose_pallet_into_container_cascades_cartons(): void
    {
        [$shipment] = $this->makeShipment();

        $component = Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->call('createPallet')    // loose pallet
            ->call('createContainer');

        $pallet = $shipment->shipmentPallets()->first();
        $container = $shipment->shipmentContainers()->first();

        // Add a carton on the loose pallet (inherits no container)
        $component->call('createCarton', null, $pallet->id);
        $carton = $shipment->cartons()->first();
        $this->assertNull($carton->shipment_container_id);

        // Move the pallet into the container
        $component->call('startMovePallet', $pallet->id)
            ->call('movePalletTo', 'container:'.$container->id);

        $pallet->refresh();
        $this->assertEquals($container->id, $pallet->shipment_container_id);

        // Carton on the pallet inherits the new container
        $carton->refresh();
        $this->assertEquals($container->id, $carton->shipment_container_id);
        $this->assertEquals($pallet->id, $carton->shipment_pallet_id);
    }

    public function test_move_pallet_out_of_container_to_loose(): void
    {
        [$shipment] = $this->makeShipment();

        $component = Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->call('createContainer');
        $container = $shipment->shipmentContainers()->first();

        $component->call('createPallet', $container->id);
        $pallet = $shipment->shipmentPallets()->first();

        $component->call('createCarton', $container->id, $pallet->id);
        $carton = $shipment->cartons()->first();

        $component->call('startMovePallet', $pallet->id)
            ->call('movePalletTo', 'loose');

        $pallet->refresh();
        $this->assertNull($pallet->shipment_container_id);

        $carton->refresh();
        $this->assertNull($carton->shipment_container_id);
        $this->assertEquals($pallet->id, $carton->shipment_pallet_id);
    }

    public function test_fill_container_via_livewire_creates_bulk_cartons(): void
    {
        // Product with pcs_per_carton via packaging
        $client = \App\Domain\CRM\Models\Company::create(['name' => 'C-'.uniqid(), 'status' => 'active']);
        $client->companyRoles()->create(['role' => 'client']);
        $inquiry = \App\Domain\Inquiries\Models\Inquiry::create([
            'reference' => 'INQ-'.uniqid(), 'company_id' => $client->id, 'status' => 'received',
            'source' => 'email', 'currency_code' => 'USD',
        ]);
        $pi = \App\Domain\ProformaInvoices\Models\ProformaInvoice::create([
            'reference' => 'PI-'.uniqid(), 'inquiry_id' => $inquiry->id, 'company_id' => $client->id,
            'currency_code' => 'USD', 'issue_date' => '2026-04-09', 'status' => 'confirmed',
        ]);
        $product = \App\Domain\Catalog\Models\Product::create(['name' => 'Widget', 'sku' => 'W-'.uniqid()]);
        \App\Domain\Catalog\Models\ProductPackaging::create([
            'product_id' => $product->id, 'packaging_type' => 'carton',
            'pcs_per_carton' => 2, 'carton_weight' => 5.0,
        ]);
        $piItem = \App\Domain\ProformaInvoices\Models\ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id, 'product_id' => $product->id, 'description' => 'Widget',
            'quantity' => 100, 'unit_price' => 1000, 'unit' => 'pcs',
        ]);
        $shipment = Shipment::create([
            'reference' => 'SHIP-'.uniqid(), 'company_id' => $client->id, 'currency_code' => 'USD',
            'status' => 'draft', 'transport_mode' => 'sea',
            'origin_port' => 'Shanghai', 'destination_port' => 'Santos',
        ]);
        $item = ShipmentItem::create([
            'shipment_id' => $shipment->id, 'proforma_invoice_item_id' => $piItem->id,
            'quantity' => 100, 'sort_order' => 0,
        ]);

        $component = Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->call('createContainer');

        $container = $shipment->shipmentContainers()->first();

        $component->call('startFillContainer', $container->id)
            ->assertSet('fillTargetType', 'container')
            ->assertSet('fillTargetId', $container->id)
            ->set('fillItemId', $item->id)
            ->assertSet('fillPieces', 100) // auto-filled with remaining
            ->call('confirmFill');

        $this->assertEquals(50, $shipment->cartons()->count()); // 100 / 2
        $this->assertEquals(50, $shipment->cartons()->where('shipment_container_id', $container->id)->count());
    }

    public function test_fill_preview_calculates_cartons(): void
    {
        [$shipment, $items] = $this->makeShipment(['w' => 100]);

        $component = Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->call('createContainer');

        $container = $shipment->shipmentContainers()->first();

        $component->call('startFillContainer', $container->id)
            ->set('fillItemId', $items['w']->id)
            ->set('fillPieces', 50);

        // Without packaging, pcs_per_carton = 0 → 1 carton with all 50 pieces
        $preview = $component->get('fillPreview');
        $this->assertEquals(1, $preview['cartons']);
        $this->assertEquals(50, $preview['per_carton']);
    }

    public function test_cancel_fill_resets_state(): void
    {
        [$shipment] = $this->makeShipment();

        $component = Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->call('createContainer');

        $container = $shipment->shipmentContainers()->first();

        $component->call('startFillContainer', $container->id)
            ->set('fillItemId', 1)
            ->set('fillPieces', 10)
            ->call('cancelFill')
            ->assertSet('fillTargetType', null)
            ->assertSet('fillTargetId', null)
            ->assertSet('fillItemId', null)
            ->assertSet('fillPieces', 0);
    }

    public function test_delete_container_leaves_contents_loose(): void
    {
        [$shipment] = $this->makeShipment();

        $component = Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->call('createContainer');
        $container = $shipment->shipmentContainers()->first();

        $component->call('createCarton', $container->id);
        $carton = $shipment->cartons()->first();

        $component->call('deleteContainer', $container->id);

        $this->assertEquals(0, $shipment->shipmentContainers()->count());
        $carton->refresh();
        $this->assertNull($carton->shipment_container_id);
    }
}
