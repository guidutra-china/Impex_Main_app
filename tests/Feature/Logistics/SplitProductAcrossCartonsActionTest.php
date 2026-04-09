<?php

namespace Tests\Feature\Logistics;

use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Actions\AddContentToCartonAction;
use App\Domain\Logistics\Actions\CreateCartonAction;
use App\Domain\Logistics\Actions\SplitProductAcrossCartonsAction;
use App\Domain\Logistics\Models\CartonContent;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class SplitProductAcrossCartonsActionTest extends TestCase
{
    use RefreshDatabase;

    private SplitProductAcrossCartonsAction $split;

    private CreateCartonAction $createCarton;

    private AddContentToCartonAction $addContent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->split = app(SplitProductAcrossCartonsAction::class);
        $this->createCarton = app(CreateCartonAction::class);
        $this->addContent = app(AddContentToCartonAction::class);
    }

    private function makeShipment(int $qty = 4): array
    {
        $client = Company::create(['name' => 'Client SP-'.uniqid(), 'status' => 'active']);
        $client->companyRoles()->create(['role' => 'client']);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-SP-'.uniqid(),
            'company_id' => $client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $pi = ProformaInvoice::create([
            'reference' => 'PI-SP-'.uniqid(),
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-04-08',
            'status' => 'confirmed',
        ]);

        $piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description' => 'Machine',
            'quantity' => $qty,
            'unit_price' => 1000,
            'unit' => 'pcs',
        ]);

        $shipment = Shipment::create([
            'reference' => 'SHIP-SP-'.uniqid(),
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'status' => 'draft',
            'transport_mode' => 'sea',
            'origin_port' => 'Shanghai',
            'destination_port' => 'Santos',
        ]);

        $item = ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $piItem->id,
            'quantity' => $qty,
            'sort_order' => 0,
        ]);

        return [$shipment, $item];
    }

    public function test_execute_persists_split_definition_on_item(): void
    {
        [, $item] = $this->makeShipment(4);

        $def = $this->split->execute($item, ['Frame', 'Accessories']);

        $this->assertArrayHasKey('set_id', $def);
        $this->assertEquals(['Frame', 'Accessories'], $def['part_labels']);

        $item->refresh();
        $this->assertNotNull($item->packing_split);
        $this->assertEquals($def['set_id'], $item->packing_split['set_id']);
        $this->assertEquals(['Frame', 'Accessories'], $item->packing_split['part_labels']);
    }

    public function test_execute_fewer_than_two_parts_throws(): void
    {
        [, $item] = $this->makeShipment();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 2');

        $this->split->execute($item, ['Frame']);
    }

    public function test_execute_trims_and_dedupes_empty_labels(): void
    {
        [, $item] = $this->makeShipment();

        $def = $this->split->execute($item, ['Frame', '', '   ', 'Frame', 'Accessories']);

        $this->assertEquals(['Frame', 'Accessories'], $def['part_labels']);
    }

    public function test_execute_throws_when_item_already_has_split(): void
    {
        [, $item] = $this->makeShipment();

        $this->split->execute($item, ['A', 'B']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already');

        $this->split->execute($item->fresh(), ['X', 'Y']);
    }

    public function test_execute_no_db_writes_to_carton_contents(): void
    {
        [, $item] = $this->makeShipment();

        $before = CartonContent::count();

        $this->split->execute($item, ['Frame', 'Accessories']);

        $this->assertEquals($before, CartonContent::count());
    }

    public function test_clear_removes_split_and_related_contents(): void
    {
        [$shipment, $item] = $this->makeShipment(4);

        $def = $this->split->execute($item, ['Frame', 'Accessories']);
        $setId = $def['set_id'];

        // Place some parts
        $boxA = $this->createCarton->execute($shipment);
        $boxB = $this->createCarton->execute($shipment);
        $this->addContent->execute($boxA, $item->fresh(), 4, 'Frame', $setId);
        $this->addContent->execute($boxB, $item->fresh(), 2, 'Accessories', $setId);

        $this->assertEquals(2, CartonContent::where('multi_box_set_id', $setId)->count());

        $removed = $this->split->clear($item->fresh());

        $this->assertEquals(2, $removed);
        $this->assertEquals(0, CartonContent::where('multi_box_set_id', $setId)->count());
        $this->assertNull($item->fresh()->packing_split);
    }

    public function test_clear_on_item_without_split_is_noop(): void
    {
        [, $item] = $this->makeShipment();

        $removed = $this->split->clear($item);

        $this->assertEquals(0, $removed);
    }

    public function test_distribute_parts_across_multiple_cartons_with_variable_pieces(): void
    {
        [$shipment, $item] = $this->makeShipment(4);

        $def = $this->split->execute($item, ['Frame', 'Accessories']);
        $setId = $def['set_id'];

        $box1 = $this->createCarton->execute($shipment);
        $box2 = $this->createCarton->execute($shipment);
        $box3 = $this->createCarton->execute($shipment);

        // User's scenario: 4 Frames in box 1, 3 Accessories in box 2, 1 Accessory in box 3
        $this->addContent->execute($box1, $item->fresh(), 4, 'Frame', $setId);
        $this->addContent->execute($box2, $item->fresh(), 3, 'Accessories', $setId);
        $this->addContent->execute($box3, $item->fresh(), 1, 'Accessories', $setId);

        $progress = app(\App\Domain\Logistics\Services\PackingProgressService::class)
            ->forShipmentItem($item->fresh());

        $this->assertEquals(4, $progress->packedComplete);
        $this->assertEquals(0, $progress->remaining());
    }
}
