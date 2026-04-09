<?php

namespace Tests\Feature\Logistics;

use App\Domain\CRM\Models\Company;
use App\Domain\Logistics\Actions\CreateCartonAction;
use App\Domain\Logistics\Actions\UpdateCartonAction;
use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Models\CartonContent;
use App\Domain\Logistics\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class UpdateCartonActionTest extends TestCase
{
    use RefreshDatabase;

    private UpdateCartonAction $update;

    private CreateCartonAction $createCarton;

    private Shipment $shipment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->update = app(UpdateCartonAction::class);
        $this->createCarton = app(CreateCartonAction::class);

        $client = Company::create(['name' => 'Client UCA-'.uniqid(), 'status' => 'active']);
        $client->companyRoles()->create(['role' => 'client']);

        $this->shipment = Shipment::create([
            'reference' => 'SHIP-UCA-'.uniqid(),
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'status' => 'draft',
            'transport_mode' => 'sea',
            'origin_port' => 'Shanghai',
            'destination_port' => 'Santos',
        ]);
    }

    private function addContent(Carton $carton, ?float $weightShare = null): CartonContent
    {
        return CartonContent::create([
            'carton_id' => $carton->id,
            'shipment_item_id' => null,
            'pieces' => 1,
            'weight_share' => $weightShare,
            'sort_order' => 1,
        ]);
    }

    public function test_updates_box_level_fields(): void
    {
        $carton = $this->createCarton->execute($this->shipment);

        $updated = $this->update->execute($carton, [
            'container_number' => 'CCLU7730065',
            'pallet_number' => 5,
            'gross_weight' => 30.5,
            'length' => 100,
            'width' => 80,
            'height' => 60,
            'notes' => 'Handle with care',
        ]);

        $this->assertEquals('CCLU7730065', $updated->container_number);
        $this->assertEquals(5, $updated->pallet_number);
        $this->assertEquals('30.500', $updated->gross_weight);
        $this->assertEquals('Handle with care', $updated->notes);
    }

    public function test_shipment_id_in_attributes_is_ignored(): void
    {
        $carton = $this->createCarton->execute($this->shipment);

        $otherClient = Company::create(['name' => 'Other-'.uniqid(), 'status' => 'active']);
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

        $updated = $this->update->execute($carton, [
            'shipment_id' => $otherShipment->id,
            'notes' => 'X',
        ]);

        $this->assertEquals($this->shipment->id, $updated->shipment_id);
    }

    public function test_no_weight_share_skips_validation(): void
    {
        $carton = $this->createCarton->execute($this->shipment, ['gross_weight' => 10.0]);
        $this->addContent($carton, null);
        $this->addContent($carton, null);

        $updated = $this->update->execute($carton, ['notes' => 'No shares']);

        $this->assertEquals('No shares', $updated->notes);
    }

    public function test_all_weight_share_matching_gross_succeeds(): void
    {
        $carton = $this->createCarton->execute($this->shipment, ['gross_weight' => 10.0]);
        $this->addContent($carton, 6.0);
        $this->addContent($carton, 4.0);

        $updated = $this->update->execute($carton, ['notes' => 'Shares OK']);

        $this->assertEquals('Shares OK', $updated->notes);
    }

    public function test_all_weight_share_mismatching_gross_throws(): void
    {
        $carton = $this->createCarton->execute($this->shipment, ['gross_weight' => 10.0]);
        $this->addContent($carton, 6.0);
        $this->addContent($carton, 3.0); // sum 9.0 != 10.0

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not equal');
        $this->update->execute($carton, ['notes' => 'X']);
    }

    public function test_mixed_state_some_shares_some_not_throws(): void
    {
        $carton = $this->createCarton->execute($this->shipment, ['gross_weight' => 10.0]);
        $this->addContent($carton, 6.0);
        $this->addContent($carton, null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('all-or-nothing');
        $this->update->execute($carton, ['notes' => 'X']);
    }

    public function test_weight_share_without_gross_weight_throws(): void
    {
        $carton = $this->createCarton->execute($this->shipment);
        $this->addContent($carton, 5.0);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('gross_weight is null');
        $this->update->execute($carton, ['notes' => 'X']);
    }

    public function test_three_content_equal_split_validates(): void
    {
        // 10.000 / 3 = 3.333..., stored as 3.333 each → sum 9.999, diff 0.001
        // abs(0.001) > 0.001 is false, so should pass the tolerance gate.
        $carton = $this->createCarton->execute($this->shipment, ['gross_weight' => 9.999]);
        $this->addContent($carton, 3.333);
        $this->addContent($carton, 3.333);
        $this->addContent($carton, 3.333);

        $updated = $this->update->execute($carton, ['notes' => 'Three-way OK']);

        $this->assertEquals('Three-way OK', $updated->notes);
    }
}
