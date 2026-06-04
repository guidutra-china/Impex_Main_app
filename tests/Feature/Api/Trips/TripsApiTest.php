<?php

namespace Tests\Feature\Api\Trips;

use App\Domain\CRM\Models\Company;
use App\Domain\Travel\Enums\TravelExpenseCategory;
use App\Domain\Travel\Enums\TripStatus;
use App\Domain\Travel\Models\Trip;
use App\Domain\Users\Enums\UserType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TripsApiTest extends TestCase
{
    use RefreshDatabase;

    private function internalUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => 'Internal Operator',
            'email' => 'ops-'.uniqid().'@impex.test',
            'password' => bcrypt('password'),
            'type' => UserType::INTERNAL,
            'status' => 'active',
        ], $overrides));
    }

    private function supplier(): Company
    {
        $company = Company::create(['name' => 'Supplier Co', 'status' => 'active']);
        $company->companyRoles()->create(['role' => 'supplier']);

        return $company;
    }

    public function test_login_succeeds_with_valid_internal_user(): void
    {
        $user = $this->internalUser();

        $this->postJson('/api/trips/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'iPhone 15',
        ])->assertOk()->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);
    }

    public function test_store_creates_trip_with_expense_and_receipt(): void
    {
        Storage::fake('public');
        $user = $this->internalUser();
        $supplier = $this->supplier();
        Sanctum::actingAs($user, ['trips:manage']);

        $response = $this->post('/api/trips/v1/trips', [
            'client_uuid' => '11111111-1111-4111-8111-111111111111',
            'title' => 'Canton Fair',
            'company_id' => $supplier->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-05',
            'destination_city' => 'Guangzhou',
            'destination_country' => 'CN',
            'expenses' => [
                [
                    'category' => TravelExpenseCategory::LODGING->value,
                    'description' => 'Hotel',
                    'amount' => '450.00',
                    'currency_code' => 'CNY',
                    'expense_date' => '2026-06-02',
                    'photos' => [UploadedFile::fake()->image('receipt.jpg')],
                ],
            ],
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('trip.status', TripStatus::DRAFT->value)
            ->assertJsonPath('trip.expense_count', 1);

        $trip = Trip::firstWhere('client_uuid', '11111111-1111-4111-8111-111111111111');
        $this->assertNotNull($trip);
        $this->assertSame($user->id, $trip->user_id);
        $this->assertSame($supplier->id, $trip->company_id);
        $this->assertCount(1, $trip->expenses);
        Storage::disk('public')->assertExists($trip->expenses->first()->photos->first()->path);
    }

    public function test_expenses_added_incrementally_then_finalized(): void
    {
        $user = $this->internalUser();
        $supplier = $this->supplier();
        Sanctum::actingAs($user, ['trips:manage']);

        // 1) Create the trip header (draft, no expenses yet).
        $this->postJson('/api/trips/v1/trips', [
            'client_uuid' => '33333333-3333-4333-8333-333333333333',
            'title' => 'Multi-day trip',
            'company_id' => $supplier->id,
            'start_date' => '2026-06-01',
        ])->assertCreated()->assertJsonPath('trip.status', TripStatus::DRAFT->value);

        // 2) Add a first expense to the same trip.
        $this->postJson('/api/trips/v1/trips', [
            'client_uuid' => '33333333-3333-4333-8333-333333333333',
            'title' => 'Multi-day trip',
            'company_id' => $supplier->id,
            'start_date' => '2026-06-01',
            'expenses' => [[
                'client_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                'category' => 'meals',
                'amount' => '20',
                'currency_code' => 'CNY',
                'expense_date' => '2026-06-01 12:30',
            ]],
        ])->assertCreated()->assertJsonPath('trip.expense_count', 1);

        // 3) Finalize: re-send the same expense (deduped) + finalize flag.
        $this->postJson('/api/trips/v1/trips', [
            'client_uuid' => '33333333-3333-4333-8333-333333333333',
            'title' => 'Multi-day trip',
            'company_id' => $supplier->id,
            'start_date' => '2026-06-01',
            'finalize' => true,
            'expenses' => [[
                'client_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                'category' => 'meals',
                'amount' => '20',
                'currency_code' => 'CNY',
                'expense_date' => '2026-06-01 12:30',
            ]],
        ])->assertCreated()
            ->assertJsonPath('trip.status', TripStatus::SUBMITTED->value)
            ->assertJsonPath('trip.expense_count', 1);

        $this->assertSame(1, Trip::count());
        $this->assertSame(1, Trip::first()->expenses()->count());
    }

    public function test_store_is_idempotent_per_client_uuid(): void
    {
        $user = $this->internalUser();
        $supplier = $this->supplier();
        Sanctum::actingAs($user, ['trips:manage']);

        $payload = [
            'client_uuid' => '22222222-2222-4222-8222-222222222222',
            'title' => 'Repeat',
            'company_id' => $supplier->id,
            'start_date' => '2026-06-01',
            'expenses' => [[
                'category' => TravelExpenseCategory::TRANSPORT->value,
                'amount' => '30',
                'currency_code' => 'CNY',
                'expense_date' => '2026-06-01',
            ]],
        ];

        $first = $this->postJson('/api/trips/v1/trips', $payload)->assertCreated();
        $second = $this->postJson('/api/trips/v1/trips', $payload)->assertCreated();

        $this->assertSame($first->json('trip.id'), $second->json('trip.id'));
        $this->assertSame(1, Trip::count());
    }

    public function test_store_rejects_trip_without_company_or_internal(): void
    {
        $user = $this->internalUser();
        Sanctum::actingAs($user, ['trips:manage']);

        $this->postJson('/api/trips/v1/trips', [
            'title' => 'Orphan trip',
            'start_date' => '2026-06-01',
        ])->assertStatus(422)->assertJsonValidationErrors('company_id');
    }

    public function test_internal_trip_is_accepted_without_company(): void
    {
        $user = $this->internalUser();
        Sanctum::actingAs($user, ['trips:manage']);

        $this->postJson('/api/trips/v1/trips', [
            'title' => 'Internal visit',
            'is_internal' => true,
            'start_date' => '2026-06-01',
        ])->assertCreated()->assertJsonPath('trip.is_internal', true);
    }

    public function test_index_returns_only_the_authenticated_users_trips(): void
    {
        $user = $this->internalUser();
        $other = $this->internalUser();
        $supplier = $this->supplier();

        Trip::create([
            'title' => 'Mine', 'user_id' => $user->id, 'company_id' => $supplier->id,
            'start_date' => '2026-06-01', 'status' => TripStatus::SUBMITTED,
        ]);
        Trip::create([
            'title' => 'Theirs', 'user_id' => $other->id, 'company_id' => $supplier->id,
            'start_date' => '2026-06-01', 'status' => TripStatus::SUBMITTED,
        ]);

        Sanctum::actingAs($user, ['trips:manage']);

        $this->getJson('/api/trips/v1/trips')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Mine');
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/trips/v1/trips')->assertUnauthorized();
    }

    public function test_recoverable_returns_only_users_draft_trips_with_expenses(): void
    {
        $user = $this->internalUser();
        $supplier = $this->supplier();
        Sanctum::actingAs($user, ['trips:manage']);

        $draft = Trip::create([
            'title' => 'Draft', 'user_id' => $user->id, 'company_id' => $supplier->id,
            'client_uuid' => 'rec-uuid-1', 'start_date' => '2026-06-01', 'status' => TripStatus::DRAFT,
        ]);
        $draft->expenses()->create([
            'client_uuid' => 'rec-exp-1', 'category' => 'meals', 'amount' => 200000,
            'currency_code' => 'CNY', 'expense_date' => '2026-06-01 12:00',
        ]);

        // A submitted trip must NOT be recoverable.
        Trip::create([
            'title' => 'Sent', 'user_id' => $user->id, 'company_id' => $supplier->id,
            'client_uuid' => 'rec-uuid-2', 'start_date' => '2026-06-01', 'status' => TripStatus::SUBMITTED,
        ]);

        $this->getJson('/api/trips/v1/trips/recoverable')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.client_uuid', 'rec-uuid-1')
            ->assertJsonCount(1, 'data.0.expenses')
            ->assertJsonPath('data.0.expenses.0.client_uuid', 'rec-exp-1');
    }

    public function test_reference_data_labels_follow_the_users_locale(): void
    {
        // SetLocale middleware honours the authenticated user's locale, so the
        // category labels come back translated — never as raw keys.
        $ptUser = $this->internalUser(['locale' => 'pt_BR']);
        Sanctum::actingAs($ptUser, ['trips:manage']);

        $pt = collect($this->getJson('/api/trips/v1/reference-data')->assertOk()->json('expense_categories'));
        $this->assertSame('Hospedagem', $pt->firstWhere('value', 'lodging')['label']);

        $enUser = $this->internalUser(['locale' => 'en']);
        Sanctum::actingAs($enUser, ['trips:manage']);

        $en = collect($this->getJson('/api/trips/v1/reference-data')->assertOk()->json('expense_categories'));
        $this->assertSame('Lodging', $en->firstWhere('value', 'lodging')['label']);
        $this->assertStringNotContainsString('enums.', $en->firstWhere('value', 'lodging')['label']);
    }
}
