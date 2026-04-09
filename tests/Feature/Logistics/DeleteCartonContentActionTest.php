<?php

namespace Tests\Feature\Logistics;

use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Actions\AddContentToCartonAction;
use App\Domain\Logistics\Actions\CreateCartonAction;
use App\Domain\Logistics\Actions\DeleteCartonContentAction;
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

class DeleteCartonContentActionTest extends TestCase
{
    use RefreshDatabase;

    private DeleteCartonContentAction $delete;

    private AddContentToCartonAction $add;

    private CreateCartonAction $createCarton;

    private PackingProgressService $progress;

    protected function setUp(): void
    {
        parent::setUp();
        $this->delete = app(DeleteCartonContentAction::class);
        $this->add = app(AddContentToCartonAction::class);
        $this->createCarton = app(CreateCartonAction::class);
        $this->progress = app(PackingProgressService::class);
    }

    private function makeShipment(int $qty = 10): array
    {
        $client = Company::create(['name' => 'Client DCC-'.uniqid(), 'status' => 'active']);
        $client->companyRoles()->create(['role' => 'client']);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-DCC-'.uniqid(),
            'company_id' => $client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $pi = ProformaInvoice::create([
            'reference' => 'PI-DCC-'.uniqid(),
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
            'reference' => 'SHIP-DCC-'.uniqid(),
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

    public function test_delete_one_content_leaves_siblings(): void
    {
        [$shipment, $item] = $this->makeShipment(10);
        $carton = $this->createCarton->execute($shipment);

        $a = $this->add->execute($carton, $item, 3);
        $b = $this->add->execute($carton, $item, 4);

        $this->delete->execute($a);

        $this->assertNull(CartonContent::find($a->id));
        $this->assertNotNull(CartonContent::find($b->id));
    }

    public function test_deleting_frees_pieces_in_progress_service(): void
    {
        [$shipment, $item] = $this->makeShipment(10);
        $carton = $this->createCarton->execute($shipment);

        $content = $this->add->execute($carton, $item, 7);

        $beforeProgress = $this->progress->forShipmentItem($item);
        $this->assertEquals(7, $beforeProgress->packedComplete);
        $this->assertEquals(3, $beforeProgress->remaining());

        $this->delete->execute($content);

        $afterProgress = $this->progress->forShipmentItem($item);
        $this->assertEquals(0, $afterProgress->packedComplete);
        $this->assertEquals(10, $afterProgress->remaining());
    }

    public function test_deleting_one_part_of_split_drops_complete_count(): void
    {
        [$shipment, $item] = $this->makeShipment(1);

        $setId = (string) Str::ulid();
        $item->update([
            'packing_split' => ['set_id' => $setId, 'part_labels' => ['Frame', 'Accessories']],
        ]);

        $boxA = $this->createCarton->execute($shipment);
        $boxB = $this->createCarton->execute($shipment);

        $partA = $this->add->execute($boxA, $item, 1, 'Frame', $setId);
        $this->add->execute($boxB, $item, 1, 'Accessories', $setId);

        // Both parts placed → MIN(1,1) = 1 complete
        $this->assertEquals(1, $this->progress->forShipmentItem($item)->packedComplete);

        // Delete Frame → Frame sum = 0, Accessories sum = 1, MIN = 0
        $this->delete->execute($partA);

        $progress = $this->progress->forShipmentItem($item);
        $this->assertEquals(0, $progress->packedComplete, 'Missing Frame means 0 complete units');
        $this->assertEquals(1, $progress->remainingForPart('Frame'));
        $this->assertEquals(0, $progress->remainingForPart('Accessories'));
    }

    public function test_deleting_last_member_of_split_part_resets_progress(): void
    {
        [$shipment, $item] = $this->makeShipment(1);

        $setId = (string) Str::ulid();
        $item->update([
            'packing_split' => ['set_id' => $setId, 'part_labels' => ['Frame', 'Accessories']],
        ]);

        $box = $this->createCarton->execute($shipment);
        $content = $this->add->execute($box, $item, 1, 'Frame', $setId);

        $this->delete->execute($content);

        $progress = $this->progress->forShipmentItem($item);
        $this->assertEquals(0, $progress->packedComplete);
    }
}
