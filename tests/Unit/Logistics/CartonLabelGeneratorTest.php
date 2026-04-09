<?php

namespace Tests\Unit\Logistics;

use App\Domain\CRM\Models\Company;
use App\Domain\Logistics\Actions\CreateCartonAction;
use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartonLabelGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private CreateCartonAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = app(CreateCartonAction::class);
    }

    private function makeShipment(string $suffix = ''): Shipment
    {
        $client = Company::create(['name' => 'Client CL'.$suffix.uniqid(), 'status' => 'active']);
        $client->companyRoles()->create(['role' => 'client']);

        return Shipment::create([
            'reference' => 'SHIP-CL-'.uniqid(),
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'status' => 'draft',
            'transport_mode' => 'sea',
            'origin_port' => 'Shanghai',
            'destination_port' => 'Santos',
        ]);
    }

    public function test_empty_shipment_produces_box_001(): void
    {
        $shipment = $this->makeShipment();

        $carton = $this->action->execute($shipment);

        $this->assertEquals('BOX-001', $carton->label);
    }

    public function test_sequential_increments(): void
    {
        $shipment = $this->makeShipment();

        $a = $this->action->execute($shipment);
        $b = $this->action->execute($shipment);
        $c = $this->action->execute($shipment);

        $this->assertEquals('BOX-001', $a->label);
        $this->assertEquals('BOX-002', $b->label);
        $this->assertEquals('BOX-003', $c->label);
    }

    public function test_gaps_are_not_filled_uses_max_plus_one(): void
    {
        $shipment = $this->makeShipment();

        Carton::create(['shipment_id' => $shipment->id, 'label' => 'BOX-001', 'packaging_type' => 'CARTON']);
        Carton::create(['shipment_id' => $shipment->id, 'label' => 'BOX-005', 'packaging_type' => 'CARTON']);

        $carton = $this->action->execute($shipment);

        $this->assertEquals('BOX-006', $carton->label);
    }

    public function test_non_matching_labels_are_ignored(): void
    {
        $shipment = $this->makeShipment();

        Carton::create(['shipment_id' => $shipment->id, 'label' => 'WOOD-CRATE-A', 'packaging_type' => 'WOOD_BOX']);
        Carton::create(['shipment_id' => $shipment->id, 'label' => 'BOX-002', 'packaging_type' => 'CARTON']);

        $carton = $this->action->execute($shipment);

        $this->assertEquals('BOX-003', $carton->label);
    }

    public function test_multi_shipment_isolation(): void
    {
        $shipmentA = $this->makeShipment('A');
        $shipmentB = $this->makeShipment('B');

        $this->action->execute($shipmentA);
        $this->action->execute($shipmentA);
        $this->action->execute($shipmentA);

        $firstB = $this->action->execute($shipmentB);

        $this->assertEquals('BOX-001', $firstB->label, 'Shipment B should start at BOX-001 regardless of shipment A');
    }
}
