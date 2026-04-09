<?php

namespace Tests\Feature\Logistics;

use App\Domain\CRM\Models\Company;
use App\Domain\Logistics\Actions\CreateCartonAction;
use App\Domain\Logistics\Actions\CreateContainerAction;
use App\Domain\Logistics\Actions\CreatePalletAction;
use App\Domain\Logistics\Actions\DeleteContainerAction;
use App\Domain\Logistics\Actions\DeletePalletAction;
use App\Domain\Logistics\Actions\UpdateContainerAction;
use App\Domain\Logistics\Actions\UpdatePalletAction;
use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentContainer;
use App\Domain\Logistics\Models\ShipmentPallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContainerPalletHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private Shipment $shipment;

    private CreateContainerAction $createContainer;

    private CreatePalletAction $createPallet;

    private CreateCartonAction $createCarton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createContainer = app(CreateContainerAction::class);
        $this->createPallet = app(CreatePalletAction::class);
        $this->createCarton = app(CreateCartonAction::class);

        $client = Company::create(['name' => 'Client CPH-'.uniqid(), 'status' => 'active']);
        $client->companyRoles()->create(['role' => 'client']);

        $this->shipment = Shipment::create([
            'reference' => 'SHIP-CPH-'.uniqid(),
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'status' => 'draft',
            'transport_mode' => 'sea',
            'origin_port' => 'Shanghai',
            'destination_port' => 'Santos',
        ]);
    }

    public function test_create_container_auto_generates_label(): void
    {
        $container = $this->createContainer->execute($this->shipment);

        $this->assertEquals('CONT-001', $container->label);

        $container2 = $this->createContainer->execute($this->shipment);
        $this->assertEquals('CONT-002', $container2->label);
    }

    public function test_create_container_with_attributes(): void
    {
        $container = $this->createContainer->execute($this->shipment, [
            'container_number' => 'CCLU7730065',
            'type' => '40HQ',
            'seal_number' => 'CN12345',
        ]);

        $this->assertEquals('CCLU7730065', $container->container_number);
        $this->assertEquals('40HQ', $container->type);
        $this->assertEquals('CN12345', $container->seal_number);
    }

    public function test_update_container(): void
    {
        $container = $this->createContainer->execute($this->shipment);

        app(UpdateContainerAction::class)->execute($container, [
            'container_number' => 'MSCU1234567',
            'type' => '20GP',
        ]);

        $container->refresh();
        $this->assertEquals('MSCU1234567', $container->container_number);
        $this->assertEquals('20GP', $container->type);
    }

    public function test_update_container_ignores_label_change(): void
    {
        $container = $this->createContainer->execute($this->shipment);

        app(UpdateContainerAction::class)->execute($container, [
            'label' => 'HACKED-LABEL',
            'container_number' => 'ABCD1234567',
        ]);

        $container->refresh();
        $this->assertEquals('CONT-001', $container->label);
        $this->assertEquals('ABCD1234567', $container->container_number);
    }

    public function test_delete_container_orphans_pallets_and_cartons(): void
    {
        $container = $this->createContainer->execute($this->shipment);

        $pallet = $this->createPallet->execute($this->shipment, [
            'shipment_container_id' => $container->id,
        ]);

        $cartonInPallet = $this->createCarton->execute($this->shipment, [
            'shipment_container_id' => $container->id,
            'shipment_pallet_id' => $pallet->id,
        ]);

        $cartonDirect = $this->createCarton->execute($this->shipment, [
            'shipment_container_id' => $container->id,
        ]);

        app(DeleteContainerAction::class)->execute($container);

        $this->assertNull(ShipmentContainer::find($container->id));
        $this->assertNotNull(ShipmentPallet::find($pallet->id));
        $this->assertNull($pallet->fresh()->shipment_container_id, 'Pallet is now loose');
        $this->assertNotNull(Carton::find($cartonInPallet->id));
        $this->assertNull($cartonInPallet->fresh()->shipment_container_id);
        $this->assertNotNull(Carton::find($cartonDirect->id));
        $this->assertNull($cartonDirect->fresh()->shipment_container_id);
    }

    public function test_create_pallet_auto_label(): void
    {
        $p1 = $this->createPallet->execute($this->shipment);
        $p2 = $this->createPallet->execute($this->shipment);

        $this->assertEquals('PLT-001', $p1->label);
        $this->assertEquals('PLT-002', $p2->label);
    }

    public function test_create_pallet_inside_container(): void
    {
        $container = $this->createContainer->execute($this->shipment);
        $pallet = $this->createPallet->execute($this->shipment, [
            'shipment_container_id' => $container->id,
        ]);

        $this->assertEquals($container->id, $pallet->shipment_container_id);
    }

    public function test_delete_pallet_preserves_cartons_in_container(): void
    {
        $container = $this->createContainer->execute($this->shipment);
        $pallet = $this->createPallet->execute($this->shipment, [
            'shipment_container_id' => $container->id,
        ]);

        $carton = $this->createCarton->execute($this->shipment, [
            'shipment_container_id' => $container->id,
            'shipment_pallet_id' => $pallet->id,
        ]);

        app(DeletePalletAction::class)->execute($pallet);

        $this->assertNull(ShipmentPallet::find($pallet->id));
        $carton->refresh();
        $this->assertEquals($container->id, $carton->shipment_container_id, 'Carton keeps container');
        $this->assertNull($carton->shipment_pallet_id, 'Carton leaves pallet');
    }

    public function test_update_pallet_can_move_to_different_container(): void
    {
        $containerA = $this->createContainer->execute($this->shipment);
        $containerB = $this->createContainer->execute($this->shipment);

        $pallet = $this->createPallet->execute($this->shipment, [
            'shipment_container_id' => $containerA->id,
        ]);

        app(UpdatePalletAction::class)->execute($pallet, [
            'shipment_container_id' => $containerB->id,
        ]);

        $pallet->refresh();
        $this->assertEquals($containerB->id, $pallet->shipment_container_id);
    }

    public function test_carton_effective_container_via_pallet(): void
    {
        $container = $this->createContainer->execute($this->shipment);
        $pallet = $this->createPallet->execute($this->shipment, [
            'shipment_container_id' => $container->id,
        ]);

        // Carton has pallet_id set but container_id null — should still resolve via pallet
        $carton = $this->createCarton->execute($this->shipment, [
            'shipment_pallet_id' => $pallet->id,
        ]);

        $this->assertEquals($container->id, $carton->getEffectiveContainer()->id);
    }

    public function test_container_total_weight_aggregates_cartons(): void
    {
        $container = $this->createContainer->execute($this->shipment);

        $this->createCarton->execute($this->shipment, [
            'shipment_container_id' => $container->id,
            'gross_weight' => 10.5,
        ]);
        $this->createCarton->execute($this->shipment, [
            'shipment_container_id' => $container->id,
            'gross_weight' => 5.0,
        ]);

        $this->assertEqualsWithDelta(15.5, $container->fresh()->total_gross_weight, 0.01);
    }

    public function test_pallet_total_weight_aggregates_cartons_on_it(): void
    {
        $pallet = $this->createPallet->execute($this->shipment);

        $this->createCarton->execute($this->shipment, [
            'shipment_pallet_id' => $pallet->id,
            'gross_weight' => 8.0,
        ]);
        $this->createCarton->execute($this->shipment, [
            'shipment_pallet_id' => $pallet->id,
            'gross_weight' => 12.0,
        ]);

        $this->assertEqualsWithDelta(20.0, $pallet->fresh()->total_gross_weight, 0.01);
    }

    public function test_loose_carton_has_null_parents(): void
    {
        $carton = $this->createCarton->execute($this->shipment);

        $this->assertNull($carton->shipment_container_id);
        $this->assertNull($carton->shipment_pallet_id);
        $this->assertNull($carton->getEffectiveContainer());
    }
}
