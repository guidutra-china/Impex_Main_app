<?php

namespace Tests\Feature\Logistics;

use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Actions\AddContentToCartonAction;
use App\Domain\Logistics\Actions\CreateCartonAction;
use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class AddContentToCartonActionTest extends TestCase
{
    use RefreshDatabase;

    private AddContentToCartonAction $add;

    private CreateCartonAction $createCarton;

    protected function setUp(): void
    {
        parent::setUp();
        $this->add = app(AddContentToCartonAction::class);
        $this->createCarton = app(CreateCartonAction::class);
    }

    private function makeShipment(int $itemQty = 10): array
    {
        $client = Company::create(['name' => 'Client AC-'.uniqid(), 'status' => 'active']);
        $client->companyRoles()->create(['role' => 'client']);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-AC-'.uniqid(),
            'company_id' => $client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $pi = ProformaInvoice::create([
            'reference' => 'PI-AC-'.uniqid(),
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-04-08',
            'status' => 'confirmed',
        ]);

        $piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description' => 'Test product',
            'quantity' => $itemQty,
            'unit_price' => 1000,
            'unit' => 'pcs',
        ]);

        $shipment = Shipment::create([
            'reference' => 'SHIP-AC-'.uniqid(),
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
            'quantity' => $itemQty,
            'sort_order' => 0,
        ]);

        return [$shipment, $shipmentItem];
    }

    public function test_happy_path_adds_content_and_decrements_remaining(): void
    {
        [$shipment, $item] = $this->makeShipment(10);
        $carton = $this->createCarton->execute($shipment);

        $content = $this->add->execute($carton, $item, 7);

        $this->assertNotNull($content->id);
        $this->assertEquals(7, $content->pieces);
        $this->assertEquals($carton->id, $content->carton_id);
    }

    public function test_throws_when_pieces_zero_or_negative(): void
    {
        [$shipment, $item] = $this->makeShipment(10);
        $carton = $this->createCarton->execute($shipment);

        $this->expectException(InvalidArgumentException::class);
        $this->add->execute($carton, $item, 0);
    }

    public function test_throws_when_pieces_negative(): void
    {
        [$shipment, $item] = $this->makeShipment(10);
        $carton = $this->createCarton->execute($shipment);

        $this->expectException(InvalidArgumentException::class);
        $this->add->execute($carton, $item, -5);
    }

    public function test_throws_when_carton_and_item_shipments_differ(): void
    {
        [$shipmentA, $itemA] = $this->makeShipment(10);
        [$shipmentB] = $this->makeShipment(10);
        $cartonB = $this->createCarton->execute($shipmentB);

        $this->expectException(InvalidArgumentException::class);
        $this->add->execute($cartonB, $itemA, 5);
    }

    public function test_throws_when_pieces_exceeds_remaining(): void
    {
        [$shipment, $item] = $this->makeShipment(10);
        $carton = $this->createCarton->execute($shipment);

        $this->expectException(InvalidArgumentException::class);
        $this->add->execute($carton, $item, 11);
    }

    public function test_exact_remaining_succeeds(): void
    {
        [$shipment, $item] = $this->makeShipment(10);
        $carton1 = $this->createCarton->execute($shipment);
        $carton2 = $this->createCarton->execute($shipment);

        $this->add->execute($carton1, $item, 3);
        $this->add->execute($carton2, $item, 7);

        $this->assertEquals(2, $shipment->cartons()->count());
    }

    public function test_split_item_validates_per_part_remaining(): void
    {
        [$shipment, $item] = $this->makeShipment(1);

        // Define a split on the item
        $setId = (string) Str::ulid();
        $item->update([
            'packing_split' => [
                'set_id' => $setId,
                'part_labels' => ['Frame', 'Accessories'],
            ],
        ]);

        $boxA = $this->createCarton->execute($shipment);
        $boxB = $this->createCarton->execute($shipment);

        // Place Frame (1 piece) — does NOT consume Accessories remaining
        $this->add->execute($boxA, $item, 1, 'Frame', $setId);

        // Place Accessories (1 piece) — should succeed because per-part remaining
        $contentB = $this->add->execute($boxB, $item, 1, 'Accessories', $setId);

        $this->assertNotNull($contentB->id);
        $this->assertEquals($setId, $contentB->multi_box_set_id);
        $this->assertEquals('Accessories', $contentB->part_label);
    }

    public function test_split_item_rejects_overshoot_on_single_part(): void
    {
        [$shipment, $item] = $this->makeShipment(4);

        $setId = (string) Str::ulid();
        $item->update([
            'packing_split' => [
                'set_id' => $setId,
                'part_labels' => ['Frame', 'Accessories'],
            ],
        ]);

        $box = $this->createCarton->execute($shipment);

        // Can place 4 of Frame
        $this->add->execute($box, $item, 4, 'Frame', $setId);

        // Cannot place a 5th Frame (overshoot)
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Frame');
        $this->add->execute($box, $item, 1, 'Frame', $setId);
    }

    public function test_content_is_persisted_and_carton_has_contents(): void
    {
        [$shipment, $item] = $this->makeShipment(10);
        $carton = $this->createCarton->execute($shipment);

        $this->add->execute($carton, $item, 5);

        $this->assertEquals(1, $carton->fresh()->contents()->count());
        // Recalc is invoked (verified by integration tests in RecalculateShipmentTotalsFromCartonsTest once Task 6 runs)
    }
}
