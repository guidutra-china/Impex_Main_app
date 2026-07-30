<?php

namespace Tests\Feature\Operations;

use App\Domain\Logistics\Models\Shipment;
use App\Filament\Resources\Shipments\Pages\ListShipments;
use App\Filament\Resources\Shipments\Pages\ViewShipment;
use App\Filament\Resources\Shipments\RelationManagers\ItemsRelationManager;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class QuickViewActionTest extends TestCase
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

    public function test_row_click_triggers_quick_view_instead_of_navigation(): void
    {
        $shipment = Shipment::factory()->create();

        $table = Livewire::test(ListShipments::class)->instance()->getTable();

        $this->assertSame('quickView', $table->getRecordAction($shipment));
        $this->assertNull($table->getRecordUrl($shipment));
    }

    public function test_quick_view_opens_slide_over_with_record_details(): void
    {
        $shipment = Shipment::factory()->create();

        Livewire::test(ListShipments::class)
            ->mountTableAction('quickView', $shipment)
            ->assertSuccessful()
            ->assertMountedActionModalSee($shipment->reference)
            ->assertMountedActionModalSee(ItemsRelationManager::getTitle($shipment, ViewShipment::class));
    }
}
