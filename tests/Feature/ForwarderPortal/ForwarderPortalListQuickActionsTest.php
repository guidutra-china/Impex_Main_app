<?php

namespace Tests\Feature\ForwarderPortal;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Users\Enums\UserType;
use App\Events\ShipmentEtaChangedByForwarder;
use App\Filament\ForwarderPortal\Resources\ShipmentResource;
use App\Filament\ForwarderPortal\Resources\ShipmentResource\Pages\ListShipments;
use App\Models\User;
use Database\Seeders\ForwarderPortalRolesSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ForwarderPortalListQuickActionsTest extends TestCase
{
    use RefreshDatabase;

    private Company $clientCompany;

    private Company $forwarderCompany;

    private User $forwarderUser;

    private User $responsibleAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->seed(ForwarderPortalRolesSeeder::class);

        $this->clientCompany = Company::create(['name' => 'Acme Imports', 'status' => 'active']);
        $this->clientCompany->companyRoles()->create(['role' => CompanyRole::CLIENT->value]);

        $this->forwarderCompany = Company::create(['name' => 'Sea Logistics', 'status' => 'active']);
        $this->forwarderCompany->companyRoles()->create(['role' => CompanyRole::FORWARDER->value]);

        $this->forwarderUser = User::create([
            'name' => 'Forwarder Operator',
            'email' => 'ops@sea-logistics.test',
            'password' => bcrypt('secret'),
            'type' => UserType::FORWARDER,
            'status' => 'active',
            'company_id' => $this->forwarderCompany->id,
        ]);
        $this->forwarderUser->assignRole('forwarder_full');

        $this->responsibleAdmin = User::create([
            'name' => 'Admin Owner',
            'email' => 'admin@impex.test',
            'password' => bcrypt('secret'),
            'type' => UserType::INTERNAL,
            'status' => 'active',
        ]);

        Filament::setCurrentPanel('forwarder-portal');
        $this->actingAs($this->forwarderUser);
        Filament::setTenant($this->forwarderCompany);
    }

    private function makeShipment(array $attributes = []): Shipment
    {
        return Shipment::create(array_merge([
            'reference' => 'SHIP-QA-'.fake()->unique()->numerify('####'),
            'company_id' => $this->clientCompany->id,
            'status' => ShipmentStatus::IN_TRANSIT,
            'currency_code' => 'USD',
            'forwarder_company_id' => $this->forwarderCompany->id,
            'responsible_user_id' => $this->responsibleAdmin->id,
            'eta' => now()->subDay()->toDateString(),
        ], $attributes));
    }

    public function test_status_column_action_advances_customs_to_in_transit(): void
    {
        $shipment = $this->makeShipment(['status' => ShipmentStatus::CUSTOMS]);

        Livewire::test(ListShipments::class)
            ->callAction(TestAction::make('changeStatus')->table($shipment));

        $this->assertSame(ShipmentStatus::IN_TRANSIT, $shipment->refresh()->status);
    }

    public function test_status_column_action_advances_in_transit_to_arrived_with_actual_arrival_today(): void
    {
        $shipment = $this->makeShipment([
            'status' => ShipmentStatus::IN_TRANSIT,
            'eta' => now()->subDays(2)->toDateString(),
        ]);

        Livewire::test(ListShipments::class)
            ->callAction(TestAction::make('changeStatus')->table($shipment), data: [
                'set_actual_arrival_today' => true,
            ]);

        $shipment->refresh();
        $this->assertSame(ShipmentStatus::ARRIVED, $shipment->status);
        $this->assertSame(now()->toDateString(), $shipment->actual_arrival?->toDateString());
    }

    public function test_status_column_action_blocks_arrived_before_eta(): void
    {
        $shipment = $this->makeShipment([
            'status' => ShipmentStatus::IN_TRANSIT,
            'eta' => now()->addDays(3)->toDateString(),
        ]);

        Livewire::test(ListShipments::class)
            ->callAction(TestAction::make('changeStatus')->table($shipment), data: [
                'set_actual_arrival_today' => true,
            ]);

        $shipment->refresh();
        $this->assertSame(ShipmentStatus::IN_TRANSIT, $shipment->status);
        $this->assertNull($shipment->actual_arrival);
    }

    public function test_next_forwarder_status_helpers(): void
    {
        $booked = $this->makeShipment(['status' => ShipmentStatus::BOOKED]);
        $customs = $this->makeShipment(['status' => ShipmentStatus::CUSTOMS]);
        $arrived = $this->makeShipment(['status' => ShipmentStatus::ARRIVED]);

        // BOOKED only transitions to CUSTOMS/CANCELLED — nothing a forwarder may set.
        $this->assertNull(ShipmentResource::nextForwarderStatus($booked));
        $this->assertSame(ShipmentStatus::IN_TRANSIT, ShipmentResource::nextForwarderStatus($customs));

        // ARRIVED is the end of the flow; going back to IN_TRANSIT is an undo,
        // not a "next" status.
        $this->assertNull(ShipmentResource::nextForwarderStatus($arrived));
        $this->assertTrue(ShipmentResource::canRevertToInTransit($arrived));
        $this->assertFalse(ShipmentResource::canRevertToInTransit($customs));

        $this->assertSame(ShipmentStatus::BOOKED, ShipmentResource::previousStatusInFlow($customs));
        $this->assertSame(ShipmentStatus::IN_TRANSIT, ShipmentResource::previousStatusInFlow($arrived));
    }

    public function test_arrived_shipment_can_be_reverted_to_in_transit_from_modal(): void
    {
        $shipment = $this->makeShipment([
            'status' => ShipmentStatus::ARRIVED,
            'eta' => now()->subDays(2)->toDateString(),
            'actual_arrival' => now()->subDay()->toDateString(),
        ]);

        Livewire::test(ListShipments::class)
            ->callAction([
                TestAction::make('changeStatus')->table($shipment),
                'revertToInTransit',
            ]);

        $shipment->refresh();
        $this->assertSame(ShipmentStatus::IN_TRANSIT, $shipment->status);
        $this->assertNull($shipment->actual_arrival);
    }

    public function test_eta_column_action_updates_schedule_and_fires_event(): void
    {
        Event::fake([ShipmentEtaChangedByForwarder::class]);

        $shipment = $this->makeShipment([
            'etd' => '2026-08-01',
            'eta' => '2026-08-20',
        ]);

        Livewire::test(ListShipments::class)
            ->callAction(TestAction::make('updateScheduleFromEta')->table($shipment), data: [
                'etd' => '2026-08-02',
                'eta' => '2026-08-25',
            ]);

        $shipment->refresh();
        $this->assertSame('2026-08-02', $shipment->etd?->toDateString());
        $this->assertSame('2026-08-25', $shipment->eta?->toDateString());

        Event::assertDispatched(ShipmentEtaChangedByForwarder::class);
    }

    public function test_etd_column_action_exists_for_quick_date_editing(): void
    {
        $shipment = $this->makeShipment();

        Livewire::test(ListShipments::class)
            ->assertActionExists(TestAction::make('updateScheduleFromEtd')->table($shipment))
            ->assertActionExists(TestAction::make('changeStatus')->table($shipment));
    }
}
