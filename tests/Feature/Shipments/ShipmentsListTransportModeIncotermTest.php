<?php

namespace Tests\Feature\Shipments;

use App\Domain\CRM\Models\Company;
use App\Domain\Logistics\Enums\TransportMode;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Quotations\Enums\Incoterm;
use App\Filament\Resources\Shipments\Pages\ListShipments;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class ShipmentsListTransportModeIncotermTest extends TestCase
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

    public function test_transport_mode_column_appends_incoterm_code(): void
    {
        $client = Company::factory()->create();
        $shipment = Shipment::factory()->create([
            'company_id' => $client->id,
            'transport_mode' => TransportMode::SEA,
            'incoterm' => Incoterm::FOB,
        ]);

        Livewire::test(ListShipments::class)
            ->assertCanSeeTableRecords([$shipment])
            ->assertTableColumnFormattedStateSet(
                'transport_mode',
                TransportMode::SEA->getLabel().' · FOB',
                $shipment,
            );
    }

    public function test_transport_mode_column_without_incoterm_shows_only_mode(): void
    {
        $client = Company::factory()->create();
        $shipment = Shipment::factory()->create([
            'company_id' => $client->id,
            'transport_mode' => TransportMode::AIR,
            'incoterm' => null,
        ]);

        Livewire::test(ListShipments::class)
            ->assertCanSeeTableRecords([$shipment])
            ->assertTableColumnFormattedStateSet(
                'transport_mode',
                TransportMode::AIR->getLabel(),
                $shipment,
            );
    }
}
