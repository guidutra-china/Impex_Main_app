<?php

namespace Tests\Feature\ForwarderPortal;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Users\Enums\UserType;
use App\Events\ShipmentEtaChangedByForwarder;
use App\Models\User;
use Database\Seeders\ForwarderPortalRolesSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ForwarderPortalAccessTest extends TestCase
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
    }

    public function test_forwarder_user_can_access_forwarder_portal(): void
    {
        $panel = Filament::getPanel('forwarder-portal');

        $this->assertTrue($this->forwarderUser->canAccessPanel($panel));
    }

    public function test_supplier_user_cannot_access_forwarder_portal(): void
    {
        $supplierCompany = Company::create(['name' => 'Suppl Co', 'status' => 'active']);
        $supplierCompany->companyRoles()->create(['role' => CompanyRole::SUPPLIER->value]);

        $supplier = User::create([
            'name' => 'Supplier User',
            'email' => 'sup@example.test',
            'password' => bcrypt('secret'),
            'type' => UserType::SUPPLIER,
            'status' => 'active',
            'company_id' => $supplierCompany->id,
        ]);

        $panel = Filament::getPanel('forwarder-portal');

        $this->assertFalse($supplier->canAccessPanel($panel));
    }

    public function test_forwarder_without_company_role_is_rejected(): void
    {
        // Strip the FORWARDER role from the company.
        $this->forwarderCompany->companyRoles()->delete();

        $panel = Filament::getPanel('forwarder-portal');

        $this->assertFalse($this->forwarderUser->fresh()->canAccessPanel($panel));
    }

    public function test_forwarder_only_sees_shipments_assigned_to_their_company(): void
    {
        $mine = Shipment::create([
            'reference' => 'SHIP-MINE',
            'company_id' => $this->clientCompany->id,
            'status' => ShipmentStatus::IN_TRANSIT,
            'currency_code' => 'USD',
            'forwarder_company_id' => $this->forwarderCompany->id,
            'responsible_user_id' => $this->responsibleAdmin->id,
            'eta' => '2026-06-01',
        ]);

        $otherForwarder = Company::create(['name' => 'Other Forwarder', 'status' => 'active']);
        $otherForwarder->companyRoles()->create(['role' => CompanyRole::FORWARDER->value]);

        Shipment::create([
            'reference' => 'SHIP-OTHER',
            'company_id' => $this->clientCompany->id,
            'status' => ShipmentStatus::IN_TRANSIT,
            'currency_code' => 'USD',
            'forwarder_company_id' => $otherForwarder->id,
            'responsible_user_id' => $this->responsibleAdmin->id,
        ]);

        $visible = Shipment::query()->forForwarderCompany($this->forwarderCompany->id)->pluck('reference');

        $this->assertSame(['SHIP-MINE'], $visible->all());
        $this->assertSame($mine->id, Shipment::query()->forForwarderCompany($this->forwarderCompany->id)->first()->id);
    }

    public function test_eta_change_event_flags_shipment_and_records_user(): void
    {
        $shipment = Shipment::create([
            'reference' => 'SHIP-FLAG',
            'company_id' => $this->clientCompany->id,
            'status' => ShipmentStatus::IN_TRANSIT,
            'currency_code' => 'USD',
            'forwarder_company_id' => $this->forwarderCompany->id,
            'responsible_user_id' => $this->responsibleAdmin->id,
            'eta' => '2026-06-01',
        ]);

        // Sanity: flag starts false.
        $this->assertFalse((bool) $shipment->eta_change_pending_recalc);

        event(new ShipmentEtaChangedByForwarder(
            shipment: $shipment,
            previousEta: '2026-06-01',
            newEta: '2026-06-15',
            userId: $this->forwarderUser->id,
        ));

        $shipment->refresh();
        $this->assertTrue((bool) $shipment->eta_change_pending_recalc);
        $this->assertNotNull($shipment->eta_changed_at);
        $this->assertSame($this->forwarderUser->id, $shipment->eta_changed_by);
    }

    public function test_event_dispatch_can_be_observed(): void
    {
        Event::fake([ShipmentEtaChangedByForwarder::class]);

        event(new ShipmentEtaChangedByForwarder(
            shipment: Shipment::create([
                'reference' => 'SHIP-EVT',
                'company_id' => $this->clientCompany->id,
                'status' => ShipmentStatus::IN_TRANSIT,
                'currency_code' => 'USD',
                'forwarder_company_id' => $this->forwarderCompany->id,
                'responsible_user_id' => $this->responsibleAdmin->id,
                'eta' => '2026-06-01',
            ]),
            previousEta: '2026-06-01',
            newEta: '2026-06-10',
            userId: $this->forwarderUser->id,
        ));

        Event::assertDispatched(ShipmentEtaChangedByForwarder::class);
    }

    public function test_admin_widget_recalc_clears_pending_flag(): void
    {
        $shipment = Shipment::create([
            'reference' => 'SHIP-RCALC',
            'company_id' => $this->clientCompany->id,
            'status' => ShipmentStatus::IN_TRANSIT,
            'currency_code' => 'USD',
            'forwarder_company_id' => $this->forwarderCompany->id,
            'responsible_user_id' => $this->responsibleAdmin->id,
            'eta' => '2026-06-15',
            'eta_change_pending_recalc' => true,
            'eta_changed_at' => now(),
            'eta_changed_by' => $this->forwarderUser->id,
        ]);

        $this->actingAs($this->responsibleAdmin);

        $widget = new \App\Filament\Widgets\OperationalAlertsWidget;
        $widget->recalcShipment($shipment->id);

        $shipment->refresh();
        $this->assertFalse((bool) $shipment->eta_change_pending_recalc);
        // eta_changed_at/by are intentionally preserved as history so the
        // client portal widget can still surface the change for 14 days.
        $this->assertNotNull($shipment->eta_changed_at);
    }

    public function test_only_responsible_admin_can_recalc(): void
    {
        $shipment = Shipment::create([
            'reference' => 'SHIP-DENIED',
            'company_id' => $this->clientCompany->id,
            'status' => ShipmentStatus::IN_TRANSIT,
            'currency_code' => 'USD',
            'forwarder_company_id' => $this->forwarderCompany->id,
            'responsible_user_id' => $this->responsibleAdmin->id,
            'eta' => '2026-06-15',
            'eta_change_pending_recalc' => true,
            'eta_changed_at' => now(),
        ]);

        $otherAdmin = User::create([
            'name' => 'Other Admin',
            'email' => 'other@impex.test',
            'password' => bcrypt('secret'),
            'type' => UserType::INTERNAL,
            'status' => 'active',
        ]);
        $this->actingAs($otherAdmin);

        $widget = new \App\Filament\Widgets\OperationalAlertsWidget;
        $widget->recalcShipment($shipment->id);

        $shipment->refresh();
        // Flag must remain set because the recalc was unauthorized.
        $this->assertTrue((bool) $shipment->eta_change_pending_recalc);
    }
}
