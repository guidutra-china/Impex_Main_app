<?php

namespace Tests\Feature\Operations;

use App\Domain\Planning\Enums\ProductionScheduleStatus;
use App\Domain\Planning\Models\ProductionSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionScheduleStateMachineTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_approval_chain_transitions_and_logs(): void
    {
        $schedule = ProductionSchedule::factory()->create([
            'status' => ProductionScheduleStatus::Draft->value,
        ]);

        $schedule->transitionTo(ProductionScheduleStatus::PendingApproval->value);
        $schedule->transitionTo(ProductionScheduleStatus::Approved->value);
        $schedule->transitionTo(ProductionScheduleStatus::Completed->value);

        $this->assertSame(
            ProductionScheduleStatus::Completed->value,
            $schedule->fresh()->status->value,
        );

        $this->assertDatabaseHas('state_transitions', [
            'model_type' => $schedule->getMorphClass(),
            'model_id' => $schedule->getKey(),
            'from_status' => ProductionScheduleStatus::Draft->value,
            'to_status' => ProductionScheduleStatus::PendingApproval->value,
        ]);
        $this->assertSame(3, $schedule->stateTransitions()->count());
    }

    public function test_rejected_can_be_resubmitted(): void
    {
        $schedule = ProductionSchedule::factory()->create([
            'status' => ProductionScheduleStatus::PendingApproval->value,
        ]);

        $schedule->transitionTo(ProductionScheduleStatus::Rejected->value);
        $schedule->transitionTo(ProductionScheduleStatus::PendingApproval->value);

        $this->assertSame(
            ProductionScheduleStatus::PendingApproval->value,
            $schedule->fresh()->status->value,
        );
    }

    public function test_invalid_transition_throws(): void
    {
        $schedule = ProductionSchedule::factory()->create([
            'status' => ProductionScheduleStatus::Draft->value,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $schedule->transitionTo(ProductionScheduleStatus::Completed->value);
    }
}
