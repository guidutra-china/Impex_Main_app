<?php

namespace Tests\Feature;

use App\Domain\CRM\Models\Company;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use App\Filament\Resources\Shipments\Pages\ListShipments;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Quick actions on the admin Shipments list: clicking the status badge opens
 * a change-status modal, and clicking ETD/ETA opens a dates modal — mirroring
 * the Forwarder Portal list.
 */
class AdminShipmentsListQuickActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');

        $user = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $user->id ? true : null);
        $this->actingAs($user);
    }

    private function shipment(ShipmentStatus $status = ShipmentStatus::DRAFT): Shipment
    {
        return Shipment::factory()->create([
            'company_id' => Company::factory()->create()->id,
            'status' => $status->value,
        ]);
    }

    public function test_status_badge_action_transitions_shipment_through_state_machine(): void
    {
        $shipment = $this->shipment(ShipmentStatus::DRAFT);

        Livewire::test(ListShipments::class)
            ->callAction(TestAction::make('changeStatus')->table($shipment), data: [
                'status' => ShipmentStatus::BOOKED->value,
            ]);

        $this->assertSame(
            ShipmentStatus::BOOKED,
            $shipment->fresh()->status,
        );
    }

    public function test_status_badge_action_is_hidden_when_no_transition_is_allowed(): void
    {
        $shipment = $this->shipment(ShipmentStatus::CANCELLED);

        Livewire::test(ListShipments::class)
            ->assertActionHidden(TestAction::make('changeStatus')->table($shipment));
    }

    public function test_eta_cell_action_updates_etd_and_eta(): void
    {
        $shipment = $this->shipment();

        Livewire::test(ListShipments::class)
            ->callAction(TestAction::make('updateScheduleFromEta')->table($shipment), data: [
                'etd' => '2026-09-01',
                'eta' => '2026-09-20',
            ]);

        $fresh = $shipment->fresh();
        $this->assertSame('2026-09-01', $fresh->etd?->toDateString());
        $this->assertSame('2026-09-20', $fresh->eta?->toDateString());
    }

    public function test_etd_cell_action_exists_independently(): void
    {
        $shipment = $this->shipment();

        Livewire::test(ListShipments::class)
            ->assertActionExists(TestAction::make('updateScheduleFromEtd')->table($shipment));
    }
}
