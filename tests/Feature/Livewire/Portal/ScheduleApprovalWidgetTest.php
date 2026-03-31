<?php

namespace Tests\Feature\Livewire\Portal;

use App\Domain\Planning\Enums\ProductionScheduleStatus;
use App\Domain\Planning\Models\ProductionSchedule;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Livewire\Portal\ScheduleApprovalWidget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ScheduleApprovalWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_approves_schedule_and_transitions_status(): void
    {
        $pi       = ProformaInvoice::factory()->create();
        $schedule = ProductionSchedule::factory()->create([
            'proforma_invoice_id' => $pi->id,
            'status'              => ProductionScheduleStatus::PendingApproval,
        ]);

        Livewire::test(ScheduleApprovalWidget::class, ['schedule' => $schedule])
            ->call('approve');

        $schedule->refresh();
        $this->assertEquals(ProductionScheduleStatus::Approved, $schedule->status);
        $this->assertNotNull($schedule->approved_at);
        $this->assertEquals($this->user->id, $schedule->approved_by);
    }

    public function test_rejects_schedule_with_a_note(): void
    {
        $pi       = ProformaInvoice::factory()->create();
        $schedule = ProductionSchedule::factory()->create([
            'proforma_invoice_id' => $pi->id,
            'status'              => ProductionScheduleStatus::PendingApproval,
        ]);

        Livewire::test(ScheduleApprovalWidget::class, ['schedule' => $schedule])
            ->set('approvalNote', 'Quantities too low for week 2')
            ->call('reject');

        $schedule->refresh();
        $this->assertEquals(ProductionScheduleStatus::Rejected, $schedule->status);
        $this->assertEquals('Quantities too low for week 2', $schedule->approval_notes);
    }

    public function test_requires_a_note_when_rejecting(): void
    {
        $pi       = ProformaInvoice::factory()->create();
        $schedule = ProductionSchedule::factory()->create([
            'proforma_invoice_id' => $pi->id,
            'status'              => ProductionScheduleStatus::PendingApproval,
        ]);

        Livewire::test(ScheduleApprovalWidget::class, ['schedule' => $schedule])
            ->set('approvalNote', '')
            ->call('reject')
            ->assertHasErrors(['approvalNote']);

        $this->assertEquals(ProductionScheduleStatus::PendingApproval, $schedule->fresh()->status);
    }

    public function test_does_not_show_approve_reject_when_status_is_not_pending_approval(): void
    {
        $pi       = ProformaInvoice::factory()->create();
        $schedule = ProductionSchedule::factory()->create([
            'proforma_invoice_id' => $pi->id,
            'status'              => ProductionScheduleStatus::Draft,
        ]);

        Livewire::test(ScheduleApprovalWidget::class, ['schedule' => $schedule])
            ->assertDontSee('Approve Schedule');
    }
}
