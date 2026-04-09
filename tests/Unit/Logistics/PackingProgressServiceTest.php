<?php

namespace Tests\Unit\Logistics;

use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Enums\PackingProgressStatus;
use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Models\CartonContent;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\Logistics\Services\PackingProgressService;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PackingProgressServiceTest extends TestCase
{
    use RefreshDatabase;

    private PackingProgressService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PackingProgressService::class);
    }

    private function makeShipmentWithItems(array $itemQuantities): array
    {
        $client = Company::create(['name' => 'Client PP-'.uniqid(), 'status' => 'active']);
        $client->companyRoles()->create(['role' => 'client']);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-PP-'.uniqid(),
            'company_id' => $client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $pi = ProformaInvoice::create([
            'reference' => 'PI-PP-'.uniqid(),
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-04-08',
            'status' => 'confirmed',
        ]);

        $shipment = Shipment::create([
            'reference' => 'SHIP-PP-'.uniqid(),
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'status' => 'draft',
            'transport_mode' => 'sea',
            'origin_port' => 'Shanghai',
            'destination_port' => 'Santos',
        ]);

        $items = [];
        foreach ($itemQuantities as $key => $qty) {
            $piItem = ProformaInvoiceItem::create([
                'proforma_invoice_id' => $pi->id,
                'description' => "Product {$key}",
                'quantity' => $qty,
                'unit_price' => 1000,
                'unit' => 'pcs',
            ]);

            $items[$key] = ShipmentItem::create([
                'shipment_id' => $shipment->id,
                'proforma_invoice_item_id' => $piItem->id,
                'quantity' => $qty,
                'sort_order' => count($items),
            ]);
        }

        return [$shipment, $items];
    }

    private function makeCarton(Shipment $shipment, string $label): Carton
    {
        return Carton::create([
            'shipment_id' => $shipment->id,
            'label' => $label,
            'packaging_type' => 'CARTON',
            'sort_order' => $shipment->cartons()->count() + 1,
        ]);
    }

    private function addContent(Carton $carton, ?int $shipmentItemId, int $pieces, ?string $setId = null, ?string $partLabel = null): CartonContent
    {
        return CartonContent::create([
            'carton_id' => $carton->id,
            'shipment_item_id' => $shipmentItemId,
            'pieces' => $pieces,
            'multi_box_set_id' => $setId,
            'part_label' => $partLabel,
            'sort_order' => $carton->contents()->count() + 1,
        ]);
    }

    public function test_empty_shipment_returns_not_started_per_item(): void
    {
        [$shipment, $items] = $this->makeShipmentWithItems(['sandals' => 100]);

        $progress = $this->service->forShipment($shipment);

        $this->assertCount(1, $progress);
        $this->assertEquals(0, $progress[$items['sandals']->id]->packedComplete);
        $this->assertEquals(100, $progress[$items['sandals']->id]->total);
        $this->assertEquals(PackingProgressStatus::NOT_STARTED, $progress[$items['sandals']->id]->status);
    }

    public function test_standalone_single_carton_counts_correctly(): void
    {
        [$shipment, $items] = $this->makeShipmentWithItems(['sandals' => 10]);

        $carton = $this->makeCarton($shipment, 'BOX-001');
        $this->addContent($carton, $items['sandals']->id, 10);

        $p = $this->service->forShipment($shipment)[$items['sandals']->id];

        $this->assertEquals(10, $p->packedComplete);
        $this->assertEquals(PackingProgressStatus::COMPLETE, $p->status);
        $this->assertEquals(0, $p->remaining());
    }

    public function test_mixed_carton_counts_each_item_separately(): void
    {
        [$shipment, $items] = $this->makeShipmentWithItems([
            'sandals' => 5,
            'socks' => 3,
        ]);

        $carton = $this->makeCarton($shipment, 'BOX-001');
        $this->addContent($carton, $items['sandals']->id, 3);
        $this->addContent($carton, $items['socks']->id, 2);

        $progress = $this->service->forShipment($shipment);

        $this->assertEquals(3, $progress[$items['sandals']->id]->packedComplete);
        $this->assertEquals(PackingProgressStatus::PARTIAL, $progress[$items['sandals']->id]->status);

        $this->assertEquals(2, $progress[$items['socks']->id]->packedComplete);
        $this->assertEquals(PackingProgressStatus::PARTIAL, $progress[$items['socks']->id]->status);
    }

    public function test_partial_standalone_returns_partial_status(): void
    {
        [$shipment, $items] = $this->makeShipmentWithItems(['sandals' => 10]);

        $this->addContent($this->makeCarton($shipment, 'BOX-001'), $items['sandals']->id, 3);
        $this->addContent($this->makeCarton($shipment, 'BOX-002'), $items['sandals']->id, 4);

        $p = $this->service->forShipment($shipment)[$items['sandals']->id];

        $this->assertEquals(7, $p->packedComplete);
        $this->assertEquals(3, $p->remaining());
        $this->assertEquals(PackingProgressStatus::PARTIAL, $p->status);
    }

    public function test_split_item_counts_min_across_parts(): void
    {
        [$shipment, $items] = $this->makeShipmentWithItems(['machine' => 1]);

        // Define a split on the item
        $setId = (string) Str::ulid();
        $items['machine']->update([
            'packing_split' => [
                'set_id' => $setId,
                'part_labels' => ['Frame', 'Accessories'],
            ],
        ]);

        // Place 1 piece of each part
        $this->addContent($this->makeCarton($shipment, 'BOX-001'), $items['machine']->id, 1, $setId, 'Frame');
        $this->addContent($this->makeCarton($shipment, 'BOX-002'), $items['machine']->id, 1, $setId, 'Accessories');

        $p = $this->service->forShipment($shipment)[$items['machine']->id];

        $this->assertEquals(1, $p->packedComplete, 'Split with all parts placed should count as 1 unit');
        $this->assertEquals(PackingProgressStatus::COMPLETE, $p->status);
        $this->assertTrue($p->isSplit());
        $this->assertCount(2, $p->parts);
    }

    public function test_split_item_incomplete_when_only_one_part_placed(): void
    {
        [$shipment, $items] = $this->makeShipmentWithItems(['machine' => 4]);

        $setId = (string) Str::ulid();
        $items['machine']->update([
            'packing_split' => [
                'set_id' => $setId,
                'part_labels' => ['Frame', 'Accessories'],
            ],
        ]);

        // Only Frame placed, Accessories not yet
        $this->addContent($this->makeCarton($shipment, 'BOX-001'), $items['machine']->id, 4, $setId, 'Frame');

        $p = $this->service->forShipment($shipment)[$items['machine']->id];

        $this->assertEquals(0, $p->packedComplete, 'Accessories missing → MIN=0');
        $this->assertEquals(PackingProgressStatus::NOT_STARTED, $p->status);
        $this->assertEquals(4, $p->remainingForPart('Accessories'));
        $this->assertEquals(0, $p->remainingForPart('Frame'));
    }

    public function test_split_item_distributes_parts_across_multiple_cartons(): void
    {
        [$shipment, $items] = $this->makeShipmentWithItems(['machine' => 4]);

        $setId = (string) Str::ulid();
        $items['machine']->update([
            'packing_split' => [
                'set_id' => $setId,
                'part_labels' => ['Frame', 'Accessories'],
            ],
        ]);

        // 4 Frames all in box 1
        $this->addContent($this->makeCarton($shipment, 'BOX-001'), $items['machine']->id, 4, $setId, 'Frame');

        // 3 Accessories in box 2, 1 in box 3
        $this->addContent($this->makeCarton($shipment, 'BOX-002'), $items['machine']->id, 3, $setId, 'Accessories');
        $this->addContent($this->makeCarton($shipment, 'BOX-003'), $items['machine']->id, 1, $setId, 'Accessories');

        $p = $this->service->forShipment($shipment)[$items['machine']->id];

        $this->assertEquals(4, $p->packedComplete);
        $this->assertEquals(PackingProgressStatus::COMPLETE, $p->status);
        $this->assertEquals(0, $p->remainingForPart('Frame'));
        $this->assertEquals(0, $p->remainingForPart('Accessories'));
    }

    public function test_split_item_partial_progress(): void
    {
        [$shipment, $items] = $this->makeShipmentWithItems(['machine' => 4]);

        $setId = (string) Str::ulid();
        $items['machine']->update([
            'packing_split' => [
                'set_id' => $setId,
                'part_labels' => ['Frame', 'Accessories'],
            ],
        ]);

        // 4 Frames, only 2 Accessories → min(4, 2) = 2
        $this->addContent($this->makeCarton($shipment, 'BOX-001'), $items['machine']->id, 4, $setId, 'Frame');
        $this->addContent($this->makeCarton($shipment, 'BOX-002'), $items['machine']->id, 2, $setId, 'Accessories');

        $p = $this->service->forShipment($shipment)[$items['machine']->id];

        $this->assertEquals(2, $p->packedComplete);
        $this->assertEquals(PackingProgressStatus::PARTIAL, $p->status);
        $this->assertEquals(2, $p->remainingForPart('Accessories'));
        $this->assertEquals(0, $p->remainingForPart('Frame'));
    }

    public function test_content_with_null_shipment_item_id_is_ignored(): void
    {
        [$shipment, $items] = $this->makeShipmentWithItems(['sandals' => 10]);

        $carton = $this->makeCarton($shipment, 'BOX-001');
        $this->addContent($carton, $items['sandals']->id, 5);
        $this->addContent($carton, null, 100); // orphan

        $p = $this->service->forShipment($shipment)[$items['sandals']->id];

        $this->assertEquals(5, $p->packedComplete);
    }

    public function test_multiple_items_tracked_independently(): void
    {
        [$shipment, $items] = $this->makeShipmentWithItems([
            'a' => 10,
            'b' => 20,
            'c' => 30,
        ]);

        $this->addContent($this->makeCarton($shipment, 'BOX-001'), $items['a']->id, 10);
        $this->addContent($this->makeCarton($shipment, 'BOX-002'), $items['b']->id, 5);
        // 'c' has nothing

        $progress = $this->service->forShipment($shipment);

        $this->assertEquals(PackingProgressStatus::COMPLETE, $progress[$items['a']->id]->status);
        $this->assertEquals(5, $progress[$items['b']->id]->packedComplete);
        $this->assertEquals(PackingProgressStatus::PARTIAL, $progress[$items['b']->id]->status);
        $this->assertEquals(0, $progress[$items['c']->id]->packedComplete);
        $this->assertEquals(PackingProgressStatus::NOT_STARTED, $progress[$items['c']->id]->status);
    }

    public function test_for_shipment_item_returns_single_progress_dto(): void
    {
        [$shipment, $items] = $this->makeShipmentWithItems(['sandals' => 10, 'socks' => 5]);

        $this->addContent($this->makeCarton($shipment, 'BOX-001'), $items['sandals']->id, 7);

        $progress = $this->service->forShipmentItem($items['sandals']);

        $this->assertEquals(7, $progress->packedComplete);
        $this->assertEquals(3, $progress->remaining());
        $this->assertEquals(PackingProgressStatus::PARTIAL, $progress->status);
    }
}
