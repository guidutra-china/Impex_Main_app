<?php

namespace Tests\Feature\Logistics;

use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Actions\CreateCartonAction;
use App\Domain\Logistics\Actions\DeleteCartonAction;
use App\Domain\Logistics\Actions\RenumberCartonsAction;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartonLabelGenerationTest extends TestCase
{
    use RefreshDatabase;

    private function makeShipment(ShipmentStatus $status = ShipmentStatus::DRAFT): Shipment
    {
        $client = Company::create(['name' => 'Client CL-'.uniqid(), 'status' => 'active']);
        $client->companyRoles()->create(['role' => 'client']);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-CL-'.uniqid(),
            'company_id' => $client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        ProformaInvoice::create([
            'reference' => 'PI-CL-'.uniqid(),
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-04-08',
            'status' => 'confirmed',
        ]);

        return Shipment::create([
            'reference' => 'SHIP-CL-'.uniqid(),
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'status' => $status->value,
            'transport_mode' => 'sea',
            'origin_port' => 'Shanghai',
            'destination_port' => 'Santos',
        ]);
    }

    private function makeCarton(Shipment $shipment, string $label, int $sortOrder): Carton
    {
        return Carton::create([
            'shipment_id' => $shipment->id,
            'label' => $label,
            'packaging_type' => 'CARTON',
            'sort_order' => $sortOrder,
        ]);
    }

    private function labels(Shipment $shipment): array
    {
        return $shipment->cartons()->orderBy('sort_order')->orderBy('id')->pluck('label')->all();
    }

    // ---------- RenumberCartonsAction ----------

    public function test_renumber_makes_labels_contiguous(): void
    {
        $shipment = $this->makeShipment();
        $this->makeCarton($shipment, 'BOX-001', 1);
        $this->makeCarton($shipment, 'BOX-002', 2);
        $this->makeCarton($shipment, 'BOX-004', 3);
        $this->makeCarton($shipment, 'BOX-007', 4);

        $count = app(RenumberCartonsAction::class)->execute($shipment);

        $this->assertSame(4, $count);
        $this->assertSame(['BOX-001', 'BOX-002', 'BOX-003', 'BOX-004'], $this->labels($shipment));
    }

    public function test_renumber_handles_unique_constraint_via_two_pass(): void
    {
        // Scenario where a direct swap would collide:
        // Removing BOX-001 leaves [BOX-002, BOX-003]; renumber must turn
        // BOX-002 -> BOX-001 (would collide if BOX-001 still existed elsewhere
        // mid-rename — the two-pass via TMP labels prevents this).
        $shipment = $this->makeShipment();
        $this->makeCarton($shipment, 'BOX-002', 1);
        $this->makeCarton($shipment, 'BOX-003', 2);
        $this->makeCarton($shipment, 'BOX-001', 3); // out of order intentionally

        app(RenumberCartonsAction::class)->execute($shipment);

        // Resequenced by sort_order: BOX-002 -> BOX-001, BOX-003 -> BOX-002, BOX-001 -> BOX-003
        $this->assertSame(['BOX-001', 'BOX-002', 'BOX-003'], $this->labels($shipment));
    }

    public function test_renumber_resets_sort_order_to_1_to_n(): void
    {
        $shipment = $this->makeShipment();
        $this->makeCarton($shipment, 'BOX-001', 10);
        $this->makeCarton($shipment, 'BOX-002', 20);
        $this->makeCarton($shipment, 'BOX-003', 30);

        app(RenumberCartonsAction::class)->execute($shipment);

        $orders = $shipment->cartons()->orderBy('id')->pluck('sort_order')->all();
        $this->assertSame([1, 2, 3], $orders);
    }

    public function test_renumber_empty_shipment_is_noop(): void
    {
        $shipment = $this->makeShipment();
        $count = app(RenumberCartonsAction::class)->execute($shipment);
        $this->assertSame(0, $count);
    }

    // ---------- CreateCartonAction label generation ----------

    public function test_create_in_draft_uses_max_plus_one(): void
    {
        $shipment = $this->makeShipment(ShipmentStatus::DRAFT);
        $this->makeCarton($shipment, 'BOX-001', 1);
        $this->makeCarton($shipment, 'BOX-002', 2);

        $carton = app(CreateCartonAction::class)->execute($shipment);

        $this->assertSame('BOX-003', $carton->label);
    }

    public function test_create_in_non_draft_fills_first_gap(): void
    {
        $shipment = $this->makeShipment(ShipmentStatus::IN_TRANSIT);
        $this->makeCarton($shipment, 'BOX-001', 1);
        $this->makeCarton($shipment, 'BOX-002', 2);
        $this->makeCarton($shipment, 'BOX-004', 3);
        $this->makeCarton($shipment, 'BOX-005', 4);

        $carton = app(CreateCartonAction::class)->execute($shipment);

        $this->assertSame('BOX-003', $carton->label);
    }

    public function test_create_in_non_draft_no_gap_uses_max_plus_one(): void
    {
        $shipment = $this->makeShipment(ShipmentStatus::IN_TRANSIT);
        $this->makeCarton($shipment, 'BOX-001', 1);
        $this->makeCarton($shipment, 'BOX-002', 2);
        $this->makeCarton($shipment, 'BOX-003', 3);

        $carton = app(CreateCartonAction::class)->execute($shipment);

        $this->assertSame('BOX-004', $carton->label);
    }

    public function test_create_first_carton_is_box_001(): void
    {
        $shipment = $this->makeShipment(ShipmentStatus::DRAFT);
        $carton = app(CreateCartonAction::class)->execute($shipment);
        $this->assertSame('BOX-001', $carton->label);
    }

    public function test_create_in_non_draft_first_carton_when_empty_is_box_001(): void
    {
        $shipment = $this->makeShipment(ShipmentStatus::IN_TRANSIT);
        $carton = app(CreateCartonAction::class)->execute($shipment);
        $this->assertSame('BOX-001', $carton->label);
    }

    // ---------- DeleteCartonAction renumber behavior ----------

    public function test_delete_in_draft_triggers_renumber(): void
    {
        $shipment = $this->makeShipment(ShipmentStatus::DRAFT);
        $c1 = $this->makeCarton($shipment, 'BOX-001', 1);
        $c2 = $this->makeCarton($shipment, 'BOX-002', 2);
        $c3 = $this->makeCarton($shipment, 'BOX-003', 3);
        $c4 = $this->makeCarton($shipment, 'BOX-004', 4);

        app(DeleteCartonAction::class)->execute($c2);

        // c1, c3, c4 remain — should be renumbered to BOX-001, BOX-002, BOX-003
        $this->assertSame(['BOX-001', 'BOX-002', 'BOX-003'], $this->labels($shipment));
    }

    public function test_delete_in_non_draft_preserves_labels(): void
    {
        $shipment = $this->makeShipment(ShipmentStatus::IN_TRANSIT);
        $c1 = $this->makeCarton($shipment, 'BOX-001', 1);
        $c2 = $this->makeCarton($shipment, 'BOX-002', 2);
        $c3 = $this->makeCarton($shipment, 'BOX-003', 3);
        $c4 = $this->makeCarton($shipment, 'BOX-004', 4);

        app(DeleteCartonAction::class)->execute($c2);

        // Gap preserved: BOX-002 missing
        $this->assertSame(['BOX-001', 'BOX-003', 'BOX-004'], $this->labels($shipment));
    }

    public function test_delete_then_create_in_draft_remains_contiguous(): void
    {
        $shipment = $this->makeShipment(ShipmentStatus::DRAFT);
        $c1 = $this->makeCarton($shipment, 'BOX-001', 1);
        $c2 = $this->makeCarton($shipment, 'BOX-002', 2);
        $c3 = $this->makeCarton($shipment, 'BOX-003', 3);

        app(DeleteCartonAction::class)->execute($c2);
        $created = app(CreateCartonAction::class)->execute($shipment);

        // After delete: [BOX-001, BOX-002]. After create: [BOX-001, BOX-002, BOX-003]
        $this->assertSame('BOX-003', $created->label);
        $this->assertSame(['BOX-001', 'BOX-002', 'BOX-003'], $this->labels($shipment));
    }

    public function test_delete_then_create_in_non_draft_fills_the_gap(): void
    {
        $shipment = $this->makeShipment(ShipmentStatus::IN_TRANSIT);
        $c1 = $this->makeCarton($shipment, 'BOX-001', 1);
        $c2 = $this->makeCarton($shipment, 'BOX-002', 2);
        $c3 = $this->makeCarton($shipment, 'BOX-003', 3);

        app(DeleteCartonAction::class)->execute($c2);
        $created = app(CreateCartonAction::class)->execute($shipment);

        // After delete: [BOX-001, BOX-003]. After create: fills the gap with BOX-002
        $this->assertSame('BOX-002', $created->label);
    }
}
