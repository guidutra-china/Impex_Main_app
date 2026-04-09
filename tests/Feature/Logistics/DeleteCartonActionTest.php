<?php

namespace Tests\Feature\Logistics;

use App\Domain\CRM\Models\Company;
use App\Domain\Logistics\Actions\CreateCartonAction;
use App\Domain\Logistics\Actions\DeleteCartonAction;
use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Models\CartonContent;
use App\Domain\Logistics\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeleteCartonActionTest extends TestCase
{
    use RefreshDatabase;

    private DeleteCartonAction $delete;

    private CreateCartonAction $create;

    private Shipment $shipment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->delete = app(DeleteCartonAction::class);
        $this->create = app(CreateCartonAction::class);

        $client = Company::create(['name' => 'Client DCA-'.uniqid(), 'status' => 'active']);
        $client->companyRoles()->create(['role' => 'client']);

        $this->shipment = Shipment::create([
            'reference' => 'SHIP-DCA-'.uniqid(),
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'status' => 'draft',
            'transport_mode' => 'sea',
            'origin_port' => 'Shanghai',
            'destination_port' => 'Santos',
        ]);
    }

    private function addContent(Carton $carton, int $pieces = 1): CartonContent
    {
        return CartonContent::create([
            'carton_id' => $carton->id,
            'shipment_item_id' => null,
            'pieces' => $pieces,
            'sort_order' => 1,
        ]);
    }

    public function test_delete_cascades_contents(): void
    {
        $carton = $this->create->execute($this->shipment);
        $this->addContent($carton, 5);
        $this->addContent($carton, 3);

        $this->assertEquals(2, CartonContent::where('carton_id', $carton->id)->count());

        $this->delete->execute($carton);

        $this->assertNull(Carton::find($carton->id));
        $this->assertEquals(0, CartonContent::where('carton_id', $carton->id)->count());
    }

    public function test_deleting_one_carton_leaves_others(): void
    {
        $keepA = $this->create->execute($this->shipment);
        $toDelete = $this->create->execute($this->shipment);
        $keepB = $this->create->execute($this->shipment);

        $this->delete->execute($toDelete);

        $remaining = $this->shipment->cartons()->pluck('id')->all();
        $this->assertContains($keepA->id, $remaining);
        $this->assertContains($keepB->id, $remaining);
        $this->assertNotContains($toDelete->id, $remaining);
    }

    public function test_deleting_last_carton_leaves_zero_cartons(): void
    {
        $carton = $this->create->execute($this->shipment);

        $this->delete->execute($carton);

        $this->assertEquals(0, $this->shipment->cartons()->count());
    }
}
