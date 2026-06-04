<?php

namespace Tests\Feature\Travel;

use App\Domain\CRM\Models\Company;
use App\Domain\Travel\Actions\ApproveTripAction;
use App\Domain\Travel\Enums\TripStatus;
use App\Domain\Travel\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TripApprovalAndInvariantTest extends TestCase
{
    use RefreshDatabase;

    private ApproveTripAction $action;

    private User $user;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(ApproveTripAction::class);
        $this->user = User::factory()->create();
        $this->company = Company::create(['name' => 'Client Co', 'status' => 'active']);
        $this->company->companyRoles()->create(['role' => 'client']);
        $this->actingAs($this->user);
    }

    private function makeTrip(array $attributes = []): Trip
    {
        return Trip::create(array_merge([
            'title' => 'Trip',
            'company_id' => $this->company->id,
            'start_date' => '2026-06-01',
            'status' => TripStatus::SUBMITTED,
        ], $attributes));
    }

    public function test_approve_sets_status_and_approver(): void
    {
        $trip = $this->makeTrip();

        $this->action->approve($trip);

        $this->assertSame(TripStatus::APPROVED, $trip->refresh()->status);
        $this->assertSame($this->user->id, $trip->approved_by);
        $this->assertNotNull($trip->approved_at);
        $this->assertNull($trip->rejected_reason);
    }

    public function test_reject_records_reason(): void
    {
        $trip = $this->makeTrip();

        $this->action->reject($trip, 'Missing receipts');

        $this->assertSame(TripStatus::REJECTED, $trip->refresh()->status);
        $this->assertSame('Missing receipts', $trip->rejected_reason);
        $this->assertSame($this->user->id, $trip->approved_by);
    }

    public function test_trip_without_company_or_internal_flag_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->makeTrip(['company_id' => null, 'is_internal' => false]);
    }

    public function test_internal_flag_forces_company_to_null(): void
    {
        $trip = $this->makeTrip(['company_id' => $this->company->id, 'is_internal' => true]);

        $this->assertNull($trip->refresh()->company_id);
        $this->assertTrue($trip->is_internal);
    }
}
