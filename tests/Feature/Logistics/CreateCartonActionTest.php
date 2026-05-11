<?php

namespace Tests\Feature\Logistics;

use App\Domain\CRM\Models\Company;
use App\Domain\Logistics\Actions\CreateCartonAction;
use App\Domain\Logistics\Enums\PackagingType;
use App\Domain\Logistics\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateCartonActionTest extends TestCase
{
    use RefreshDatabase;

    private CreateCartonAction $action;

    private Shipment $shipment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(CreateCartonAction::class);

        $client = Company::create(['name' => 'Client CCA-'.uniqid(), 'status' => 'active']);
        $client->companyRoles()->create(['role' => 'client']);

        $this->shipment = Shipment::create([
            'reference' => 'SHIP-CCA-'.uniqid(),
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'status' => 'draft',
            'transport_mode' => 'sea',
            'origin_port' => 'Shanghai',
            'destination_port' => 'Santos',
        ]);
    }

    public function test_creates_carton_with_auto_label(): void
    {
        $carton = $this->action->execute($this->shipment);

        $this->assertNotNull($carton->id);
        $this->assertEquals('BOX-001', $carton->label);
        $this->assertEquals($this->shipment->id, $carton->shipment_id);
        $this->assertEquals(1, $this->shipment->cartons()->count());
    }

    public function test_defaults_are_applied(): void
    {
        $carton = $this->action->execute($this->shipment, [
            'container_number' => 'CCLU7730065',
            'pallet_number' => 3,
            'gross_weight' => 25.500,
            'net_weight' => 24.000,
            'length' => 50,
            'width' => 40,
            'height' => 30,
            'volume' => 0.060,
            'notes' => 'Fragile',
        ]);

        $this->assertEquals('CCLU7730065', $carton->container_number);
        $this->assertEquals(3, $carton->pallet_number);
        $this->assertEquals('25.500', $carton->gross_weight);
        $this->assertEquals('Fragile', $carton->notes);
    }

    public function test_label_in_defaults_is_ignored(): void
    {
        $carton = $this->action->execute($this->shipment, [
            'label' => 'USER-PROVIDED-LABEL',
        ]);

        $this->assertEquals('BOX-001', $carton->label, 'Server-generated label must override $defaults');
    }

    public function test_shipment_id_in_defaults_is_ignored(): void
    {
        $otherClient = Company::create(['name' => 'OtherC-'.uniqid(), 'status' => 'active']);
        $otherClient->companyRoles()->create(['role' => 'client']);

        $otherShipment = Shipment::create([
            'reference' => 'SHIP-OTHER-'.uniqid(),
            'company_id' => $otherClient->id,
            'currency_code' => 'USD',
            'status' => 'draft',
            'transport_mode' => 'sea',
            'origin_port' => 'Shanghai',
            'destination_port' => 'Santos',
        ]);

        $carton = $this->action->execute($this->shipment, [
            'shipment_id' => $otherShipment->id,
        ]);

        $this->assertEquals($this->shipment->id, $carton->shipment_id);
    }

    public function test_sort_order_auto_increments(): void
    {
        $a = $this->action->execute($this->shipment);
        $b = $this->action->execute($this->shipment);
        $c = $this->action->execute($this->shipment);

        $this->assertEquals(1, $a->sort_order);
        $this->assertEquals(2, $b->sort_order);
        $this->assertEquals(3, $c->sort_order);
    }

    public function test_default_packaging_type_is_carton(): void
    {
        $carton = $this->action->execute($this->shipment);

        $this->assertSame(PackagingType::CARTON, $carton->packaging_type);
    }
}
