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

    public function test_add_content_defaults_to_remaining_when_pieces_blank(): void
    {
        [$shipment, $items] = $this->makeShipment(['p' => 10]);

        $component = Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->call('createCarton');

        $cartonId = $shipment->cartons()->first()->id;

        // Leaving pieces at 0/blank should pack all remaining, not nothing.
        $component->call('startAddContent', $cartonId)
            ->set('addContentItemId', $items['p']->id)
            ->set('addContentPieces', 0)
            ->call('confirmAddContent');

        $content = CartonContent::where('carton_id', $cartonId)->first();
        $this->assertNotNull($content);
        $this->assertEquals(10, $content->pieces);
    }

    public function test_add_two_different_products_into_same_carton_with_partial_quantities(): void
    {
        [$shipment, $items] = $this->makeShipment(['a' => 10, 'b' => 8]);

        $component = Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->call('createCarton');

        $cartonId = $shipment->cartons()->first()->id;

        $component->call('startAddContent', $cartonId)
            ->set('addContentItemId', $items['a']->id)
            ->set('addContentPieces', 3)
            ->call('confirmAddContent');

        $component->call('startAddContent', $cartonId)
            ->set('addContentItemId', $items['b']->id)
            ->set('addContentPieces', 5)
            ->call('confirmAddContent');

        $contents = CartonContent::where('carton_id', $cartonId)->get();
        $this->assertCount(2, $contents);
        $this->assertEquals(3, $contents->firstWhere('shipment_item_id', $items['a']->id)->pieces);
        $this->assertEquals(5, $contents->firstWhere('shipment_item_id', $items['b']->id)->pieces);
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

        // Leaving the quantity blank packs all remaining (100 / 2 = 50 cartons).
        $component->call('startFillContainer', $container->id)
            ->assertSet('fillTargetType', 'container')
            ->assertSet('fillTargetId', $container->id)
            ->set('fillItemId', $item->id)
            ->call('confirmFill');

        $this->assertEquals(50, $shipment->cartons()->count()); // 100 / 2
        $this->assertEquals(50, $shipment->cartons()->where('shipment_container_id', $container->id)->count());
    }

    public function test_fill_respects_explicit_smaller_quantity(): void
    {
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

        // Explicit smaller quantity must be honoured, not the total.
        $component->call('startFillContainer', $container->id)
            ->set('fillItemId', $item->id)
            ->set('fillPieces', 10)
            ->call('confirmFill');

        $this->assertEquals(5, $shipment->cartons()->count()); // 10 / 2
    }

    public function test_set_fill_target_parses_carton(): void
    {
        [$shipment] = $this->makeShipment();

        $component = Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->call('createCarton');
        $carton = $shipment->cartons()->first();

        $component->call('setFillTarget', 'carton:'.$carton->id)
            ->assertSet('fillTargetType', 'carton')
            ->assertSet('fillTargetId', $carton->id);
    }

    public function test_pack_into_existing_carton_adds_content(): void
    {
        [$shipment, $items] = $this->makeShipment(['a' => 10, 'b' => 8]);

        $component = Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->call('createContainer');
        $container = $shipment->shipmentContainers()->first();

        $component->call('createCarton', $container->id);
        $carton = $shipment->cartons()->first();

        // Pack two different products into the same existing box.
        $component->call('startPackProduct', $items['a']->id)
            ->call('setFillTarget', 'carton:'.$carton->id)
            ->set('fillPieces', 3)
            ->call('confirmFill');

        $component->call('startPackProduct', $items['b']->id)
            ->call('setFillTarget', 'carton:'.$carton->id)
            ->set('fillPieces', 5)
            ->call('confirmFill');

        $contents = CartonContent::where('carton_id', $carton->id)->get();
        $this->assertCount(2, $contents);
        $this->assertEquals(3, $contents->firstWhere('shipment_item_id', $items['a']->id)->pieces);
        $this->assertEquals(5, $contents->firstWhere('shipment_item_id', $items['b']->id)->pieces);
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

    // ---------- Carton fill options (lista curta + busca) ----------

    /**
     * Creates $total cartons directly; the first $filled get content from $item.
     *
     * @return \Illuminate\Support\Collection<int, \App\Domain\Logistics\Models\Carton>
     */
    private function makeCartons(Shipment $shipment, ShipmentItem $item, int $total, int $filled): \Illuminate\Support\Collection
    {
        $cartons = collect(range(1, $total))->map(fn (int $i) => $shipment->cartons()->create([
            'label' => sprintf('BOX-%03d', $i),
            'sort_order' => $i,
        ]));

        $cartons->take($filled)->each(fn ($carton) => $carton->contents()->create([
            'shipment_item_id' => $item->id,
            'pieces' => 2,
        ]));

        return $cartons;
    }

    public function test_carton_fill_options_shows_shortlist_instead_of_all_cartons(): void
    {
        [$shipment, $items] = $this->makeShipment(['p' => 500]);
        $this->makeCartons($shipment, $items['p'], 50, 40);

        $component = Livewire::test(PackingListBuilder::class, ['shipment' => $shipment]);

        $options = $component->get('cartonFillOptions');

        $this->assertLessThanOrEqual(30, $options->count(), 'Select must not list every carton');
        $this->assertEquals(50, $component->get('cartonCount'));

        // Every empty box (BOX-041..BOX-050) must be offered as a candidate.
        $labels = $options->pluck('label')->implode("\n");
        foreach (range(41, 50) as $i) {
            $this->assertStringContainsString(sprintf('BOX-%03d', $i), $labels);
        }
    }

    public function test_carton_fill_options_search_filters_by_label(): void
    {
        [$shipment, $items] = $this->makeShipment(['p' => 500]);
        $this->makeCartons($shipment, $items['p'], 50, 50);

        $component = Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->set('cartonSearch', 'BOX-004');

        $options = $component->get('cartonFillOptions');

        $this->assertCount(1, $options);
        $this->assertStringContainsString('BOX-004', $options->first()['label']);
    }

    public function test_carton_fill_options_always_includes_selected_target(): void
    {
        [$shipment, $items] = $this->makeShipment(['p' => 500]);
        $cartons = $this->makeCartons($shipment, $items['p'], 50, 40);

        // A filled box early in the list is not part of the shortlist…
        $target = $cartons[4]; // BOX-005, has content
        \App\Domain\Logistics\Models\Carton::whereKey($cartons->take(40)->pluck('id'))
            ->update(['updated_at' => now()->subDay()]);

        $component = Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->call('startPackProduct', $items['p']->id)
            ->call('setFillTarget', 'carton:'.$target->id);

        // …but once selected as target it must stay visible in the select.
        $options = $component->get('cartonFillOptions');
        $this->assertTrue($options->contains('id', $target->id));
    }

    public function test_cancel_fill_resets_carton_search(): void
    {
        [$shipment] = $this->makeShipment();

        Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->set('cartonSearch', 'BOX-001')
            ->call('cancelFill')
            ->assertSet('cartonSearch', '');
    }

    public function test_bulk_edit_cartons_still_updates_whole_group(): void
    {
        [$shipment, $items] = $this->makeShipment(['p' => 500]);
        $cartons = $this->makeCartons($shipment, $items['p'], 3, 3);
        $ids = $cartons->pluck('id')->all();

        Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->call('startBulkEditCartons', $ids, 'sg-key')
            ->set('bulkEditForm.gross_weight', 12)
            ->set('bulkEditForm.length', 60)
            ->set('bulkEditForm.width', 40)
            ->set('bulkEditForm.height', 30)
            ->call('saveBulkEditCartons');

        foreach ($cartons as $carton) {
            $carton->refresh();
            $this->assertEquals('12.000', $carton->gross_weight);
            $this->assertEquals('10.800', $carton->net_weight); // auto: 90% of gross
            $this->assertEquals('0.0720', $carton->volume); // 60*40*30 / 1e6
        }
    }

    public function test_subgroups_render_empty_group_and_non_contiguous_labels(): void
    {
        [$shipment, $items] = $this->makeShipment(['p' => 500]);

        $component = Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->call('createContainer');
        $container = $shipment->shipmentContainers()->first();

        // 12 boxes in the container (> subgroup threshold of 10):
        // BOX-001 and BOX-012 share a signature (non-contiguous pair),
        // BOX-002..BOX-011 stay empty.
        $cartons = collect(range(1, 12))->map(fn (int $i) => $shipment->cartons()->create([
            'label' => sprintf('BOX-%03d', $i),
            'sort_order' => $i,
            'shipment_container_id' => $container->id,
        ]));

        foreach ([0, 11] as $index) {
            $cartons[$index]->contents()->create([
                'shipment_item_id' => $items['p']->id,
                'pieces' => 50,
            ]);
        }

        Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->assertSee('Vazias')
            ->assertSee('BOX-002 → BOX-011')
            ->assertSee('BOX-001, BOX-012')
            ->assertDontSee('BOX-001 → BOX-012');
    }

    public function test_collapsed_subgroup_renders_no_cards_and_expand_paginates(): void
    {
        [$shipment, $items] = $this->makeShipment(['p' => 5000]);

        $component = Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->call('createContainer');
        $container = $shipment->shipmentContainers()->first();

        // 30 identical boxes → one subgroup with pagination (page size 25).
        $cartons = collect(range(1, 30))->map(fn (int $i) => $shipment->cartons()->create([
            'label' => sprintf('BOX-%03d', $i),
            'sort_order' => $i,
            'shipment_container_id' => $container->id,
        ]));
        $cartons->each(fn ($carton) => $carton->contents()->create([
            'shipment_item_id' => $items['p']->id,
            'pieces' => 50,
        ]));

        $sgKey = md5(sprintf('item:%d|part:|pcs:%d', $items['p']->id, 50)).'-'.$cartons->first()->id;

        $component = Livewire::test(PackingListBuilder::class, ['shipment' => $shipment]);

        // Collapsed: no individual carton card in the HTML at all.
        $component->assertDontSeeHtml('carton-card-');

        // Expanded: first page of 25 cards, not the 30th box yet.
        $component->call('toggleSubgroup', $sgKey)
            ->assertSeeHtml('carton-card-'.$cartons[0]->id)
            ->assertSeeHtml('carton-card-'.$cartons[24]->id)
            ->assertDontSeeHtml('carton-card-'.$cartons[29]->id)
            ->assertSee('Mostrar mais');

        // Load more: the remaining 5 cards appear.
        $component->call('showMoreInSubgroup', $sgKey)
            ->assertSeeHtml('carton-card-'.$cartons[29]->id);

        // Collapse again: cards gone.
        $component->call('toggleSubgroup', $sgKey)
            ->assertDontSeeHtml('carton-card-'.$cartons[0]->id);
    }

    public function test_bulk_edit_skips_cartons_with_inconsistent_weight_share(): void
    {
        [$shipment, $items] = $this->makeShipment(['p' => 500]);

        $ok = $shipment->cartons()->create(['label' => 'BOX-001', 'sort_order' => 1]);
        $ok->contents()->create(['shipment_item_id' => $items['p']->id, 'pieces' => 10]);

        // weight_share 5.0 will not match the new gross of 12 → must be skipped.
        $bad = $shipment->cartons()->create(['label' => 'BOX-002', 'sort_order' => 2]);
        $bad->contents()->create(['shipment_item_id' => $items['p']->id, 'pieces' => 10, 'weight_share' => 5.0]);

        Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->call('startBulkEditCartons', [$ok->id, $bad->id], 'sg-key')
            ->set('bulkEditForm.gross_weight', 12)
            ->call('saveBulkEditCartons');

        $this->assertEquals('12.000', $ok->refresh()->gross_weight);
        $this->assertEquals('10.800', $ok->net_weight); // auto: 90% of gross
        $this->assertNull($bad->refresh()->gross_weight);
    }

    public function test_delete_all_cartons_removes_contents_and_renumbers(): void
    {
        [$shipment, $items] = $this->makeShipment(['p' => 500]);

        $cartons = collect(range(1, 5))->map(fn (int $i) => $shipment->cartons()->create([
            'label' => sprintf('BOX-%03d', $i),
            'sort_order' => $i,
        ]));
        $cartons->each(fn ($carton) => $carton->contents()->create([
            'shipment_item_id' => $items['p']->id,
            'pieces' => 10,
        ]));

        Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->call('deleteAllCartons', [$cartons[1]->id, $cartons[3]->id]);

        $this->assertEquals(0, CartonContent::whereIn('carton_id', [$cartons[1]->id, $cartons[3]->id])->count());

        // Draft shipment → survivors renumbered to a contiguous range.
        $labels = $shipment->cartons()->orderBy('sort_order')->pluck('label')->all();
        $this->assertSame(['BOX-001', 'BOX-002', 'BOX-003'], $labels);
    }

    public function test_toggle_container_hides_and_shows_its_contents(): void
    {
        [$shipment] = $this->makeShipment();

        $component = Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->call('createContainer');
        $container = $shipment->shipmentContainers()->first();

        $component->call('createCarton', $container->id);
        $carton = $shipment->cartons()->first();

        // Expanded by default: the carton card is rendered.
        $component->assertSeeHtml('carton-card-'.$carton->id);

        $component->call('toggleContainer', $container->id)
            ->assertDontSeeHtml('carton-card-'.$carton->id)
            ->assertSee($container->label); // header stays visible

        $component->call('toggleContainer', $container->id)
            ->assertSeeHtml('carton-card-'.$carton->id);
    }

    public function test_container_numbers_sync_to_shipment_container_number_field(): void
    {
        [$shipment] = $this->makeShipment();

        $component = Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->call('createContainer')
            ->call('createContainer');

        [$first, $second] = $shipment->shipmentContainers()->orderBy('sort_order')->get();

        $component->call('startEditContainer', $first->id)
            ->set('editContainerForm.container_number', 'CCLU7730065')
            ->call('saveEditContainer');

        $this->assertEquals('CCLU7730065', $shipment->refresh()->container_number);

        $component->call('startEditContainer', $second->id)
            ->set('editContainerForm.container_number', 'MSKU1234567')
            ->call('saveEditContainer');

        $this->assertEquals('CCLU7730065, MSKU1234567', $shipment->refresh()->container_number);

        // Removing a container re-syncs the list.
        $component->call('deleteContainer', $first->id);
        $this->assertEquals('MSKU1234567', $shipment->refresh()->container_number);
    }

    public function test_container_sync_does_not_clobber_manual_value_when_no_numbers_filled(): void
    {
        [$shipment] = $this->makeShipment();
        $shipment->update(['container_number' => 'MANUAL-123']);

        $component = Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
            ->call('createContainer');
        $container = $shipment->shipmentContainers()->first();

        // Saving a container without a number must not clear the manual field.
        $component->call('startEditContainer', $container->id)
            ->set('editContainerForm.seal_number', 'SEAL-1')
            ->call('saveEditContainer');

        $this->assertEquals('MANUAL-123', $shipment->refresh()->container_number);
    }

    public function test_products_list_shows_model_number_preferring_client_pivot_code(): void
    {
        [$shipment, $items] = $this->makeShipment(['a' => 10, 'b' => 10]);
        $client = $shipment->company;

        // Product with a client-specific code on the pivot → pivot wins.
        $withPivot = \App\Domain\Catalog\Models\Product::create([
            'name' => 'Widget A', 'sku' => 'SKU-A', 'model_number' => 'MOD-A',
        ]);
        $withPivot->companies()->attach($client->id, ['role' => 'client', 'external_code' => 'EXT-A']);

        // Product without pivot → falls back to its own model_number.
        $plain = \App\Domain\Catalog\Models\Product::create([
            'name' => 'Widget B', 'sku' => 'SKU-B', 'model_number' => 'MOD-B',
        ]);

        $items['a']->proformaInvoiceItem->update(['product_id' => $withPivot->id]);
        $items['b']->proformaInvoiceItem->update(['product_id' => $plain->id]);

        $component = Livewire::test(PackingListBuilder::class, ['shipment' => $shipment]);

        $modelNos = $component->get('products')->pluck('model_no')->all();
        $this->assertSame(['EXT-A', 'MOD-B'], $modelNos);

        $component->assertSee('EXT-A')
            ->assertSee('MOD-B')
            ->assertDontSee('MOD-A'); // pivot code takes priority over the product's own model
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
