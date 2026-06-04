<?php

namespace Tests\Feature\Travel;

use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Travel\Actions\StoreTripAction;
use App\Domain\Travel\DataTransferObjects\TripData;
use App\Domain\Travel\DataTransferObjects\TripExpenseData;
use App\Domain\Travel\Enums\TravelExpenseCategory;
use App\Domain\Travel\Enums\TripStatus;
use App\Domain\Travel\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StoreTripActionTest extends TestCase
{
    use RefreshDatabase;

    private StoreTripAction $action;

    private User $user;

    private Company $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->action = new StoreTripAction;
        $this->user = User::factory()->create();
        $this->supplier = Company::create(['name' => 'Supplier Co', 'status' => 'active']);
        $this->supplier->companyRoles()->create(['role' => 'supplier']);
    }

    public function test_it_creates_a_trip_with_expenses_and_receipt_photos(): void
    {
        $data = new TripData(
            title: 'Canton Fair',
            startDate: '2026-06-01',
            companyId: $this->supplier->id,
            userId: $this->user->id,
            destinationCity: 'Guangzhou',
            destinationCountry: 'CN',
            endDate: '2026-06-05',
            clientUuid: 'uuid-trip-1',
            expenses: [
                new TripExpenseData(
                    category: TravelExpenseCategory::LODGING->value,
                    description: 'Hotel',
                    amount: Money::toMinor(450.00),
                    currencyCode: 'CNY',
                    expenseDate: '2026-06-02 19:30',
                    photos: [UploadedFile::fake()->image('receipt.jpg')],
                ),
                new TripExpenseData(
                    category: TravelExpenseCategory::MEALS->value,
                    description: 'Dinner',
                    amount: Money::toMinor(80.50),
                    currencyCode: 'CNY',
                    expenseDate: '2026-06-02',
                ),
            ],
        );

        $trip = $this->action->execute($data);

        $this->assertDatabaseHas('trips', [
            'id' => $trip->id,
            'company_id' => $this->supplier->id,
            'is_internal' => false,
            'status' => TripStatus::DRAFT->value,
            'client_uuid' => 'uuid-trip-1',
        ]);

        $this->assertCount(2, $trip->expenses);
        $lodging = $trip->expenses->firstWhere('category', TravelExpenseCategory::LODGING);
        $this->assertSame(Money::toMinor(450.00), $lodging->amount);
        // Time-of-day must be preserved (lunch vs dinner identification).
        $this->assertSame('19:30', $lodging->expense_date->format('H:i'));
        $this->assertCount(1, $lodging->photos);
        Storage::disk('public')->assertExists($lodging->photos->first()->path);
        $this->assertTrue($lodging->photos->first()->is_primary);
    }

    public function test_same_client_uuid_does_not_create_a_duplicate(): void
    {
        $payload = fn () => new TripData(
            title: 'Repeat trip',
            startDate: '2026-06-01',
            companyId: $this->supplier->id,
            userId: $this->user->id,
            clientUuid: 'uuid-dup',
            expenses: [
                new TripExpenseData(
                    category: TravelExpenseCategory::TRANSPORT->value,
                    description: 'Taxi',
                    amount: Money::toMinor(30.00),
                    currencyCode: 'CNY',
                    expenseDate: '2026-06-01',
                    clientUuid: 'exp-dup',
                ),
            ],
        );

        $first = $this->action->execute($payload());
        $second = $this->action->execute($payload());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Trip::count());
        $this->assertSame(1, $first->refresh()->expenses->count());
    }

    public function test_expenses_are_added_incrementally_without_duplicates(): void
    {
        $header = fn (array $expenses) => new TripData(
            title: 'Multi-day trip',
            startDate: '2026-06-01',
            companyId: $this->supplier->id,
            userId: $this->user->id,
            clientUuid: 'trip-inc',
            expenses: $expenses,
        );

        $expenseA = new TripExpenseData(
            category: TravelExpenseCategory::MEALS->value,
            description: 'Lunch',
            amount: Money::toMinor(20),
            currencyCode: 'CNY',
            expenseDate: '2026-06-01 12:30',
            clientUuid: 'exp-a',
        );
        $expenseB = new TripExpenseData(
            category: TravelExpenseCategory::MEALS->value,
            description: 'Dinner',
            amount: Money::toMinor(40),
            currencyCode: 'CNY',
            expenseDate: '2026-06-01 20:00',
            clientUuid: 'exp-b',
        );

        // First sync: trip + expense A.
        $trip = $this->action->execute($header([$expenseA]));
        // Second sync (same trip): re-sends A (already there) + new B.
        $this->action->execute($header([$expenseA, $expenseB]));

        $this->assertSame(1, Trip::count());
        $this->assertSame(2, $trip->refresh()->expenses->count());
        $this->assertSame(TripStatus::DRAFT, $trip->status);
    }

    public function test_resyncing_an_expense_updates_it_in_place(): void
    {
        $make = fn (int $amount, string $category) => new TripData(
            title: 'Editable trip',
            startDate: '2026-06-01',
            companyId: $this->supplier->id,
            userId: $this->user->id,
            clientUuid: 'trip-edit',
            expenses: [new TripExpenseData(
                category: $category,
                description: 'Meal',
                amount: $amount,
                currencyCode: 'CNY',
                expenseDate: '2026-06-01 12:30',
                clientUuid: 'exp-edit',
            )],
        );

        $trip = $this->action->execute($make(Money::toMinor(20), TravelExpenseCategory::MEALS->value));
        // User noticed a mistake and corrected the amount + category.
        $this->action->execute($make(Money::toMinor(35), TravelExpenseCategory::TRANSPORT->value));

        $this->assertSame(1, $trip->refresh()->expenses->count());
        $expense = $trip->expenses->first();
        $this->assertSame(Money::toMinor(35), $expense->amount);
        $this->assertSame(TravelExpenseCategory::TRANSPORT, $expense->category);
    }

    public function test_deleted_expense_uuids_remove_the_expense(): void
    {
        $expenseA = new TripExpenseData(
            category: TravelExpenseCategory::MEALS->value,
            description: 'Wrong entry',
            amount: Money::toMinor(20),
            currencyCode: 'CNY',
            expenseDate: '2026-06-01 12:30',
            clientUuid: 'exp-del-a',
        );
        $expenseB = new TripExpenseData(
            category: TravelExpenseCategory::LODGING->value,
            description: 'Hotel',
            amount: Money::toMinor(300),
            currencyCode: 'CNY',
            expenseDate: '2026-06-01 22:00',
            clientUuid: 'exp-del-b',
        );

        $base = fn (array $expenses, array $deleted = []) => new TripData(
            title: 'Trip with deletion',
            startDate: '2026-06-01',
            companyId: $this->supplier->id,
            userId: $this->user->id,
            clientUuid: 'trip-del',
            expenses: $expenses,
            deletedExpenseUuids: $deleted,
        );

        $trip = $this->action->execute($base([$expenseA, $expenseB]));
        $this->assertSame(2, $trip->refresh()->expenses->count());

        // User deletes the wrong entry.
        $this->action->execute($base([], ['exp-del-a']));

        $this->assertSame(1, $trip->refresh()->expenses()->count());
        $this->assertSame('exp-del-b', $trip->expenses()->first()->client_uuid);
    }

    public function test_finalize_moves_draft_to_submitted(): void
    {
        $data = new TripData(
            title: 'Finished trip',
            startDate: '2026-06-01',
            companyId: $this->supplier->id,
            userId: $this->user->id,
            clientUuid: 'trip-fin',
            expenses: [],
            finalize: true,
        );

        $trip = $this->action->execute($data);

        $this->assertSame(TripStatus::SUBMITTED, $trip->refresh()->status);
    }

    public function test_internal_trip_ignores_company_id(): void
    {
        $data = new TripData(
            title: 'Internal logistics visit',
            startDate: '2026-06-01',
            companyId: $this->supplier->id, // should be ignored because internal
            isInternal: true,
            userId: $this->user->id,
            expenses: [],
        );

        $trip = $this->action->execute($data);

        $this->assertTrue($trip->is_internal);
        $this->assertNull($trip->company_id);
    }
}
