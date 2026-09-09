<?php

namespace Tests\Feature\Logistics;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Actions\GeneratePaymentScheduleAction;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use App\Filament\Resources\Shipments\Pages\ListShipments;
use App\Filament\Resources\Shipments\Pages\ViewShipment;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Embarque excluído tinha filtro "Excluídos" mas nenhum botão de restaurar.
 * Restaurar precisa regenerar o cronograma, porque a exclusão apaga as
 * parcelas sem dinheiro (ShipmentDeletionGuardTest).
 */
class ShipmentRestoreTest extends TestCase
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

    private function trashed(ShipmentStatus $status = ShipmentStatus::DRAFT): Shipment
    {
        $shipment = Shipment::factory()->create([
            'company_id' => Company::factory()->create()->id,
            'status' => $status->value,
        ]);
        $shipment->delete();

        $this->assertTrue($shipment->fresh()->trashed());

        return $shipment;
    }

    public function test_trashed_filter_lists_the_deleted_shipment_and_the_row_action_restores_it(): void
    {
        $deleted = $this->trashed();
        $alive = Shipment::factory()->create(['company_id' => Company::factory()->create()->id]);

        Livewire::test(ListShipments::class)
            ->filterTable('trashed', false)
            ->assertCanSeeTableRecords([$deleted])
            ->assertCanNotSeeTableRecords([$alive])
            ->callAction(TestAction::make('restore')->table($deleted));

        $this->assertFalse($deleted->fresh()->trashed());
    }

    public function test_bulk_restore_brings_back_every_selected_shipment(): void
    {
        $a = $this->trashed();
        $b = $this->trashed();

        Livewire::test(ListShipments::class)
            ->filterTable('trashed', false)
            ->selectTableRecords([$a, $b])
            ->callAction(TestAction::make('restore')->table()->bulk());

        $this->assertFalse($a->fresh()->trashed());
        $this->assertFalse($b->fresh()->trashed());
    }

    public function test_view_page_opens_a_trashed_shipment_and_offers_restore(): void
    {
        $deleted = $this->trashed();

        Livewire::test(ViewShipment::class, ['record' => $deleted->getKey()])
            ->assertOk()
            ->assertActionVisible('restore')
            ->callAction('restore');

        $this->assertFalse($deleted->fresh()->trashed());
    }

    public function test_restoring_an_active_shipment_regenerates_its_payment_schedule(): void
    {
        $this->mock(GeneratePaymentScheduleAction::class, function ($mock) {
            $mock->shouldReceive('regenerateForShipment')->once();
        });

        $this->trashed(ShipmentStatus::IN_TRANSIT)->restore();
    }

    public function test_restoring_a_draft_shipment_has_no_schedule_to_rebuild(): void
    {
        $this->mock(GeneratePaymentScheduleAction::class, function ($mock) {
            $mock->shouldNotReceive('regenerateForShipment');
        });

        $this->trashed(ShipmentStatus::DRAFT)->restore();
    }
}
