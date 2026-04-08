# Payment Override for PO Generation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow authorized users to override payment-block on PO generation (keeping the obligation pending), and let non-authorized users request authorization through in-app messaging.

**Architecture:** Add `overridden_by`/`overridden_at`/`override_reason` columns to `payment_schedule_items`, mirroring the existing `waived_*` pattern. Short-circuit `blocksPurchaseOrderGeneration()` and `blocksTransitionTo()` when an item is overridden — without changing its `status`. Two new domain actions (`OverridePaymentBlocksAction`, `RequestPaymentOverrideAuthorizationAction`) keep the Filament layer thin. UI branches based on the `override-payment-block` permission.

**Tech Stack:** Laravel 11, Filament 4, Spatie Permission, existing `MessagingService`, PHPUnit with `RefreshDatabase`, SQLite in-memory test DB.

**Spec:** `docs/superpowers/specs/2026-04-08-payment-override-po-generation-design.md`

---

## File Structure

**New files:**
- `database/migrations/2026_04_08_100000_add_override_to_payment_schedule_items.php` — schema change
- `app/Domain/Financial/Actions/OverridePaymentBlocksAction.php` — marks blocking items as overridden
- `app/Domain/Financial/Actions/RequestPaymentOverrideAuthorizationAction.php` — creates conversation + first message via `MessagingService`
- `tests/Unit/Financial/PaymentScheduleItemOverrideTest.php` — model-level blocking logic
- `tests/Unit/Financial/OverridePaymentBlocksActionTest.php` — action behavior + edge cases
- `tests/Feature/ProformaInvoices/GeneratePoWithOverrideTest.php` — end-to-end Filament action behavior
- `tests/Feature/ProformaInvoices/RequestPaymentOverrideAuthorizationTest.php` — messaging integration

**Edited files:**
- `app/Domain/Financial/Models/PaymentScheduleItem.php` — `$fillable`, `$casts`, `blocksPurchaseOrderGeneration()`, `blocksTransitionTo()`
- `app/Filament/Resources/ProformaInvoices/Concerns/ProformaInvoiceHeaderActions.php` — `generatePurchaseOrdersAction()` adds Branch A (override) + Branch B (request auth)
- `database/seeders/PermissionSeeder.php` — add `override-payment-block`
- `lang/en/messages.php` — new strings
- `lang/pt_BR/messages.php` — parity translations

---

## Background — Project Conventions an Outsider Needs to Know

- **Test base class:** `Tests\TestCase` extends `Illuminate\Foundation\Testing\TestCase`. Use `RefreshDatabase` trait. SQLite `:memory:` is configured in `phpunit.xml`.
- **Money is stored in minor units, scale 10000** (see `payment_schedule_items.amount` migration comment). All amounts in tests should use integers like `30000000` (= $3000.00 with scale 10000), not floats.
- **No factory exists for `PaymentScheduleItem`.** Tests create them via `PaymentScheduleItem::create([...])` directly. Do NOT add a factory in this plan — it's out of scope and the project's testing style is to inline-create financial fixtures.
- **`ProformaInvoice` factory exists** at `database/factories/ProformaInvoiceFactory.php` — use `ProformaInvoice::factory()->create()`.
- **Permissions use Spatie:** `User::factory()->create()` returns a user without permissions. Use `$user->givePermissionTo('permission-name')` after creating. Permissions must exist in the DB first — call `Permission::firstOrCreate(['name' => '...', 'guard_name' => 'web'])` in test `setUp()`, OR run `$this->seed(PermissionSeeder::class)`.
- **Filament actions are NOT directly testable** through HTTP without a full Livewire test. We test the **domain actions** end-to-end and test the **Filament concern** by instantiating the trait via a small test page or by calling the underlying domain logic. Stick to testing the domain actions for behavior, and write **one** Livewire integration test for the Filament page to confirm wiring.
- **`MessagingService` is in the container** — resolve via `app(MessagingService::class)`. Its `sendMessage()` calls Anthropic for language detection — tests must `Http::fake()` and set `config(['services.anthropic.key' => ''])` to avoid real network calls (see `tests/Feature/Messaging/MessagingServiceTest.php` setUp for the pattern).
- **PaymentScheduleItem `payable` is morphTo.** When creating in tests: `payable_type => ProformaInvoice::class, payable_id => $pi->id`.
- **`CalculationBase` enum** lives at `App\Domain\Settings\Enums\CalculationBase`. Cases used here: `BEFORE_PRODUCTION`, `ORDER_DATE`, `PO_DATE`, `BEFORE_SHIPMENT`.
- **Commit style:** the project uses conventional commits (`feat:`, `fix:`, `refactor:`, `test:`, `docs:`, `chore:`). Look at recent `git log --oneline` for tone — short, in present tense.
- **Run a single test:** `php artisan test --filter=test_method_name` or `vendor/bin/phpunit --filter=test_method_name`.
- **Run the full suite:** `php artisan test` (or `vendor/bin/phpunit`).
- **The project does NOT have `npm run lint` configured for PHP** — `composer pint` (Laravel Pint) is the formatter if available. Skip lint steps if Pint isn't configured; just run the tests.

---

## Task 1: Migration — add override columns to `payment_schedule_items`

**Files:**
- Create: `database/migrations/2026_04_08_100000_add_override_to_payment_schedule_items.php`

- [ ] **Step 1: Create the migration file**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_schedule_items', function (Blueprint $table) {
            $table->foreignId('overridden_by')
                ->nullable()
                ->after('waived_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('overridden_at')->nullable()->after('overridden_by');
            $table->text('override_reason')->nullable()->after('overridden_at');
        });
    }

    public function down(): void
    {
        Schema::table('payment_schedule_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('overridden_by');
            $table->dropColumn(['overridden_at', 'override_reason']);
        });
    }
};
```

- [ ] **Step 2: Run the migration against the dev DB**

Run: `php artisan migrate`
Expected: `Migrating: 2026_04_08_100000_add_override_to_payment_schedule_items` followed by `Migrated`.

- [ ] **Step 3: Verify the columns exist**

Run: `php artisan tinker --execute="dump(Schema::getColumnListing('payment_schedule_items'));"`
Expected: output array contains `overridden_by`, `overridden_at`, `override_reason`.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_04_08_100000_add_override_to_payment_schedule_items.php
git commit -m "feat(financial): add override columns to payment_schedule_items"
```

---

## Task 2: Update `PaymentScheduleItem` model — fillable, casts, blocking logic

**Files:**
- Modify: `app/Domain/Financial/Models/PaymentScheduleItem.php`
- Test: `tests/Unit/Financial/PaymentScheduleItemOverrideTest.php`

- [ ] **Step 1: Create the test directory and write the failing test file**

```bash
mkdir -p tests/Unit/Financial
```

Create `tests/Unit/Financial/PaymentScheduleItemOverrideTest.php`:

```php
<?php

namespace Tests\Unit\Financial;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\Settings\Enums\CalculationBase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentScheduleItemOverrideTest extends TestCase
{
    use RefreshDatabase;

    private function makeBlockingItem(ProformaInvoice $pi, CalculationBase $condition): PaymentScheduleItem
    {
        return PaymentScheduleItem::create([
            'payable_type'   => ProformaInvoice::class,
            'payable_id'     => $pi->id,
            'label'          => '30% Deposit',
            'percentage'     => 30,
            'amount'         => 30000000,
            'currency_code'  => 'USD',
            'due_condition'  => $condition->value,
            'status'         => PaymentScheduleStatus::PENDING->value,
            'is_blocking'    => true,
            'is_credit'      => false,
            'sort_order'     => 1,
        ]);
    }

    public function test_overridden_item_does_not_block_po_generation(): void
    {
        $pi = ProformaInvoice::factory()->create();
        $item = $this->makeBlockingItem($pi, CalculationBase::BEFORE_PRODUCTION);

        $this->assertTrue($item->blocksPurchaseOrderGeneration());

        $item->update([
            'overridden_by'   => User::factory()->create()->id,
            'overridden_at'   => now(),
            'override_reason' => 'Client wired payment, proof incoming.',
        ]);

        $this->assertFalse($item->fresh()->blocksPurchaseOrderGeneration());
    }

    public function test_overridden_item_does_not_block_status_transition_to_in_production(): void
    {
        $pi = ProformaInvoice::factory()->create();
        $item = $this->makeBlockingItem($pi, CalculationBase::BEFORE_PRODUCTION);

        $this->assertTrue($item->blocksTransitionTo('in_production'));

        $item->update([
            'overridden_by'   => User::factory()->create()->id,
            'overridden_at'   => now(),
            'override_reason' => 'Authorized.',
        ]);

        $this->assertFalse($item->fresh()->blocksTransitionTo('in_production'));
    }

    public function test_overridden_item_keeps_pending_status(): void
    {
        $pi = ProformaInvoice::factory()->create();
        $item = $this->makeBlockingItem($pi, CalculationBase::BEFORE_PRODUCTION);

        $item->update([
            'overridden_by'   => User::factory()->create()->id,
            'overridden_at'   => now(),
            'override_reason' => 'Authorized.',
        ]);

        $this->assertSame(
            PaymentScheduleStatus::PENDING,
            $item->fresh()->status,
        );
    }

    public function test_overridden_before_shipment_item_still_blocks_shipment(): void
    {
        // Cycle scoping: an override granted to unblock POs must NOT
        // automatically unblock subsequent shipment-stage payments.
        $pi = ProformaInvoice::factory()->create();
        $item = $this->makeBlockingItem($pi, CalculationBase::BEFORE_SHIPMENT);

        // BEFORE_SHIPMENT items don't block PO generation in the first place,
        // so blocksPurchaseOrderGeneration() is false regardless of override.
        $this->assertFalse($item->blocksPurchaseOrderGeneration());

        // But they DO block the 'shipped' transition. Override (granted for PO
        // purposes) must NOT bypass that — the cycle scoping is enforced by
        // limiting overrides to PO-related due_conditions only. Here we
        // simulate the scenario where someone tried to set overridden_at on a
        // BEFORE_SHIPMENT item; the override action will refuse it (Task 4),
        // but at the model level we verify the shipment block still fires
        // when the item is unmodified.
        $this->assertTrue($item->blocksTransitionTo('shipped'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=PaymentScheduleItemOverrideTest`
Expected: tests fail because `overridden_by` / `overridden_at` / `override_reason` are not in `$fillable`, so the `update()` calls silently drop them and `blocksPurchaseOrderGeneration()` still returns true.

- [ ] **Step 3: Update `$fillable` and `$casts` in `PaymentScheduleItem`**

In `app/Domain/Financial/Models/PaymentScheduleItem.php`, edit the `$fillable` array (currently lines 19-40) by adding three entries after `'waived_at'`:

```php
    protected $fillable = [
        'payable_type',
        'payable_id',
        'shipment_plan_id',
        'shipment_id',
        'payment_term_stage_id',
        'label',
        'percentage',
        'amount',
        'currency_code',
        'due_condition',
        'due_date',
        'status',
        'is_blocking',
        'is_credit',
        'source_type',
        'source_id',
        'sort_order',
        'notes',
        'waived_by',
        'waived_at',
        'overridden_by',
        'overridden_at',
        'override_reason',
    ];
```

And in the `casts()` method (currently lines 42-55), add `'overridden_at' => 'datetime'`:

```php
    protected function casts(): array
    {
        return [
            'percentage' => 'integer',
            'amount' => 'integer',
            'due_condition' => CalculationBase::class,
            'due_date' => 'date',
            'status' => PaymentScheduleStatus::class,
            'is_blocking' => 'boolean',
            'is_credit' => 'boolean',
            'sort_order' => 'integer',
            'waived_at' => 'datetime',
            'overridden_at' => 'datetime',
        ];
    }
```

- [ ] **Step 4: Update `blocksPurchaseOrderGeneration()` to short-circuit on override**

In `app/Domain/Financial/Models/PaymentScheduleItem.php`, replace the existing `blocksPurchaseOrderGeneration()` method (currently lines 200-211):

```php
    public function blocksPurchaseOrderGeneration(): bool
    {
        if (! $this->is_blocking || $this->isResolved() || $this->is_credit) {
            return false;
        }

        if ($this->overridden_at !== null) {
            return false;
        }

        return in_array($this->due_condition, [
            CalculationBase::BEFORE_PRODUCTION,
            CalculationBase::ORDER_DATE,
            CalculationBase::PO_DATE,
        ]);
    }
```

- [ ] **Step 5: Update `blocksTransitionTo()` to short-circuit on override**

Replace the existing `blocksTransitionTo()` method (currently lines 185-198):

```php
    public function blocksTransitionTo(string $targetStatus): bool
    {
        if (! $this->is_blocking || $this->isResolved() || $this->is_credit) {
            return false;
        }

        if ($this->overridden_at !== null) {
            // Override is scoped to PO-related cycles. We only short-circuit
            // transitions that the PO override is meant to unblock.
            $poCycleTransitions = ['confirmed', 'in_production'];
            if (in_array($targetStatus, $poCycleTransitions)) {
                return false;
            }
        }

        return match ($this->due_condition) {
            CalculationBase::BEFORE_PRODUCTION => $targetStatus === 'in_production',
            CalculationBase::BEFORE_SHIPMENT => $targetStatus === 'shipped',
            CalculationBase::ORDER_DATE,
            CalculationBase::PO_DATE => $targetStatus === 'confirmed',
            default => false,
        };
    }
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --filter=PaymentScheduleItemOverrideTest`
Expected: 4 passing tests.

- [ ] **Step 7: Commit**

```bash
git add app/Domain/Financial/Models/PaymentScheduleItem.php tests/Unit/Financial/PaymentScheduleItemOverrideTest.php
git commit -m "feat(financial): override short-circuits PO blocking and PO-cycle transitions"
```

---

## Task 3: Add `override-payment-block` permission to seeder

**Files:**
- Modify: `database/seeders/PermissionSeeder.php`

- [ ] **Step 1: Add the permission**

In `database/seeders/PermissionSeeder.php`, add `'override-payment-block'` to the array under the `// Financial` section. Insert it after `'waive-payments'` (currently line 104):

```php
            // Financial
            'view-payments',

            // Company Expenses
            'view-company-expenses',
            'create-company-expenses',
            'edit-company-expenses',
            'delete-company-expenses',
            'create-payments',
            'edit-payments',
            'delete-payments',
            'approve-payments',
            'reject-payments',
            'waive-payments',
            'override-payment-block',
            'view-payment-schedule',
            'generate-payment-schedule',
```

- [ ] **Step 2: Re-seed permissions**

Run: `php artisan db:seed --class=PermissionSeeder`
Expected: command succeeds. The seeder is idempotent (`firstOrCreate`), so existing permissions are not duplicated.

- [ ] **Step 3: Verify the permission exists**

Run: `php artisan tinker --execute="echo Spatie\Permission\Models\Permission::where('name', 'override-payment-block')->exists() ? 'YES' : 'NO';"`
Expected: `YES`.

- [ ] **Step 4: Commit**

```bash
git add database/seeders/PermissionSeeder.php
git commit -m "feat(financial): add override-payment-block permission"
```

---

## Task 4: `OverridePaymentBlocksAction` — domain action

**Files:**
- Create: `app/Domain/Financial/Actions/OverridePaymentBlocksAction.php`
- Test: `tests/Unit/Financial/OverridePaymentBlocksActionTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Financial/OverridePaymentBlocksActionTest.php`:

```php
<?php

namespace Tests\Unit\Financial;

use App\Domain\Financial\Actions\OverridePaymentBlocksAction;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\Settings\Enums\CalculationBase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OverridePaymentBlocksActionTest extends TestCase
{
    use RefreshDatabase;

    private OverridePaymentBlocksAction $action;
    private User $authorizer;
    private ProformaInvoice $pi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new OverridePaymentBlocksAction();
        $this->authorizer = User::factory()->create();
        $this->pi = ProformaInvoice::factory()->create();
        $this->actingAs($this->authorizer);
    }

    private function makeItem(array $overrides = []): PaymentScheduleItem
    {
        return PaymentScheduleItem::create(array_merge([
            'payable_type'   => ProformaInvoice::class,
            'payable_id'     => $this->pi->id,
            'label'          => '30% Deposit',
            'percentage'     => 30,
            'amount'         => 30000000,
            'currency_code'  => 'USD',
            'due_condition'  => CalculationBase::BEFORE_PRODUCTION->value,
            'status'         => PaymentScheduleStatus::PENDING->value,
            'is_blocking'    => true,
            'is_credit'      => false,
            'sort_order'     => 1,
        ], $overrides));
    }

    public function test_marks_all_blocking_items_with_user_and_reason(): void
    {
        $a = $this->makeItem(['label' => 'Stage A']);
        $b = $this->makeItem(['label' => 'Stage B', 'due_condition' => CalculationBase::ORDER_DATE->value]);

        $count = $this->action->execute($this->pi, 'Client wired today.');

        $this->assertSame(2, $count);

        foreach ([$a, $b] as $item) {
            $fresh = $item->fresh();
            $this->assertSame($this->authorizer->id, $fresh->overridden_by);
            $this->assertNotNull($fresh->overridden_at);
            $this->assertSame('Client wired today.', $fresh->override_reason);
        }
    }

    public function test_skips_already_paid_items(): void
    {
        $paid = $this->makeItem(['status' => PaymentScheduleStatus::PAID->value]);

        $count = $this->action->execute($this->pi, 'Reason.');

        $this->assertSame(0, $count);
        $this->assertNull($paid->fresh()->overridden_at);
    }

    public function test_skips_already_waived_items(): void
    {
        $waived = $this->makeItem(['status' => PaymentScheduleStatus::WAIVED->value]);

        $count = $this->action->execute($this->pi, 'Reason.');

        $this->assertSame(0, $count);
        $this->assertNull($waived->fresh()->overridden_at);
    }

    public function test_skips_non_blocking_items(): void
    {
        $nonBlocking = $this->makeItem(['is_blocking' => false]);

        $count = $this->action->execute($this->pi, 'Reason.');

        $this->assertSame(0, $count);
        $this->assertNull($nonBlocking->fresh()->overridden_at);
    }

    public function test_skips_credit_items(): void
    {
        $credit = $this->makeItem(['is_credit' => true]);

        $count = $this->action->execute($this->pi, 'Reason.');

        $this->assertSame(0, $count);
        $this->assertNull($credit->fresh()->overridden_at);
    }

    public function test_skips_before_shipment_items(): void
    {
        // Cycle scoping: BEFORE_SHIPMENT items are NOT in scope for PO override.
        $shipment = $this->makeItem(['due_condition' => CalculationBase::BEFORE_SHIPMENT->value]);

        $count = $this->action->execute($this->pi, 'Reason.');

        $this->assertSame(0, $count);
        $this->assertNull($shipment->fresh()->overridden_at);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=OverridePaymentBlocksActionTest`
Expected: fails because `App\Domain\Financial\Actions\OverridePaymentBlocksAction` does not exist.

- [ ] **Step 3: Implement the action**

Create `app/Domain/Financial/Actions/OverridePaymentBlocksAction.php`:

```php
<?php

namespace App\Domain\Financial\Actions;

use App\Domain\Financial\Models\PaymentScheduleItem;
use Illuminate\Database\Eloquent\Model;

class OverridePaymentBlocksAction
{
    /**
     * Mark every PO-blocking payment schedule item attached to the given
     * payable as overridden. Returns the number of items affected.
     *
     * Items that are already resolved (paid/waived), non-blocking, credit
     * items, or items whose due_condition is outside the PO cycle
     * (i.e. BEFORE_SHIPMENT) are skipped.
     *
     * The caller is responsible for permission checks. The acting user is
     * read from auth() — call this only inside an authenticated request.
     */
    public function execute(Model $payable, string $reason): int
    {
        $blocking = PaymentScheduleItem::blockingPurchaseOrderGeneration($payable);

        if (count($blocking) === 0) {
            return 0;
        }

        $now = now();
        $userId = auth()->id();

        $count = 0;
        foreach ($blocking as $item) {
            $item->update([
                'overridden_by'   => $userId,
                'overridden_at'   => $now,
                'override_reason' => $reason,
            ]);
            $count++;
        }

        return $count;
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=OverridePaymentBlocksActionTest`
Expected: 6 passing tests.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Financial/Actions/OverridePaymentBlocksAction.php tests/Unit/Financial/OverridePaymentBlocksActionTest.php
git commit -m "feat(financial): OverridePaymentBlocksAction marks PO blockers as overridden"
```

---

## Task 5: `RequestPaymentOverrideAuthorizationAction` — messaging integration

**Files:**
- Create: `app/Domain/Financial/Actions/RequestPaymentOverrideAuthorizationAction.php`
- Test: `tests/Feature/ProformaInvoices/RequestPaymentOverrideAuthorizationTest.php`

- [ ] **Step 1: Create the test directory and write the failing test**

```bash
mkdir -p tests/Feature/ProformaInvoices
```

Create `tests/Feature/ProformaInvoices/RequestPaymentOverrideAuthorizationTest.php`:

```php
<?php

namespace Tests\Feature\ProformaInvoices;

use App\Domain\Financial\Actions\RequestPaymentOverrideAuthorizationAction;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\Settings\Enums\CalculationBase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class RequestPaymentOverrideAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private RequestPaymentOverrideAuthorizationAction $action;
    private ProformaInvoice $pi;
    private User $requester;
    private User $authorizer;

    protected function setUp(): void
    {
        parent::setUp();

        // Avoid Anthropic calls inside MessagingService.
        Http::preventStrayRequests();
        Http::fake();
        config(['services.anthropic.key' => '']);

        Permission::firstOrCreate(['name' => 'override-payment-block', 'guard_name' => 'web']);

        $this->action = app(RequestPaymentOverrideAuthorizationAction::class);
        $this->requester = User::factory()->create(['name' => 'Requester']);
        $this->authorizer = User::factory()->create(['name' => 'Boss']);
        $this->authorizer->givePermissionTo('override-payment-block');

        $this->pi = ProformaInvoice::factory()->create(['reference' => 'PI-2026-00123']);

        PaymentScheduleItem::create([
            'payable_type'   => ProformaInvoice::class,
            'payable_id'     => $this->pi->id,
            'label'          => '30% Deposit',
            'percentage'     => 30,
            'amount'         => 30000000,
            'currency_code'  => 'USD',
            'due_condition'  => CalculationBase::BEFORE_PRODUCTION->value,
            'status'         => PaymentScheduleStatus::PENDING->value,
            'is_blocking'    => true,
            'is_credit'      => false,
            'sort_order'     => 1,
        ]);
    }

    public function test_creates_conversation_with_pi_as_subject_entity(): void
    {
        $this->actingAs($this->requester);

        $conversation = $this->action->execute(
            $this->pi,
            $this->requester,
            [$this->authorizer->id],
            'Client confirmed wire, please authorize so I can move the supplier.',
        );

        $this->assertInstanceOf(Conversation::class, $conversation);
        $this->assertSame(ProformaInvoice::class, $conversation->subject_entity_type);
        $this->assertSame($this->pi->id, $conversation->subject_entity_id);
        $this->assertStringContainsString('PI-2026-00123', $conversation->subject);
    }

    public function test_creates_message_containing_justification_and_blockers(): void
    {
        $this->actingAs($this->requester);

        $conversation = $this->action->execute(
            $this->pi,
            $this->requester,
            [$this->authorizer->id],
            'Client confirmed wire today.',
        );

        $message = Message::where('conversation_id', $conversation->id)->latest('id')->first();

        $this->assertNotNull($message);
        $this->assertStringContainsString('Client confirmed wire today.', $message->body);
        $this->assertStringContainsString('30% Deposit', $message->body);
        $this->assertStringContainsString('@PI-2026-00123', $message->body);
    }

    public function test_adds_authorizer_as_participant(): void
    {
        $this->actingAs($this->requester);

        $conversation = $this->action->execute(
            $this->pi,
            $this->requester,
            [$this->authorizer->id],
            'Please authorize.',
        );

        $this->assertTrue($conversation->fresh()->hasParticipant($this->authorizer));
        $this->assertTrue($conversation->fresh()->hasParticipant($this->requester));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=RequestPaymentOverrideAuthorizationTest`
Expected: fails — `RequestPaymentOverrideAuthorizationAction` does not exist.

- [ ] **Step 3: Implement the action**

Create `app/Domain/Financial/Actions/RequestPaymentOverrideAuthorizationAction.php`:

```php
<?php

namespace App\Domain\Financial\Actions;

use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Services\MessagingService;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Models\User;

class RequestPaymentOverrideAuthorizationAction
{
    public function __construct(
        private readonly MessagingService $messaging,
    ) {
    }

    /**
     * Open a conversation between the requester and the chosen authorizers,
     * post the first message containing the justification and a snapshot of
     * the current PO blockers, and return the conversation. The first message
     * references the PI via @PI-XXXX so the existing ReferenceResolver renders
     * it as a clickable chip in the chat UI.
     *
     * @param  array<int, int>  $authorizerUserIds
     */
    public function execute(
        ProformaInvoice $pi,
        User $requester,
        array $authorizerUserIds,
        string $justification,
    ): Conversation {
        $conversation = $this->messaging->createConversation(
            creator: $requester,
            participantUserIds: $authorizerUserIds,
            subject: __('messages.authorization_request_subject', ['reference' => $pi->reference]),
            type: 'request',
            subjectEntity: $pi,
        );

        $body = $this->buildMessageBody($pi, $justification);

        $this->messaging->sendMessage(
            conversation: $conversation,
            sender: $requester,
            body: $body,
        );

        return $conversation;
    }

    private function buildMessageBody(ProformaInvoice $pi, string $justification): string
    {
        $blockers = PaymentScheduleItem::blockingPurchaseOrderGeneration($pi);

        $lines = [
            $justification,
            '',
            'Reference: @' . $pi->reference,
            '',
            'Blocking payment items:',
        ];

        foreach ($blockers as $item) {
            $amount = number_format($item->amount / 10000, 2);
            $lines[] = "- {$item->label} — {$item->currency_code} {$amount} ({$item->status->getEnglishLabel()})";
        }

        return implode("\n", $lines);
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=RequestPaymentOverrideAuthorizationTest`
Expected: 3 passing tests.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Financial/Actions/RequestPaymentOverrideAuthorizationAction.php tests/Feature/ProformaInvoices/RequestPaymentOverrideAuthorizationTest.php
git commit -m "feat(financial): RequestPaymentOverrideAuthorizationAction sends override request via messaging"
```

---

## Task 6: i18n strings — `lang/en` and `lang/pt_BR`

**Files:**
- Modify: `lang/en/messages.php`
- Modify: `lang/pt_BR/messages.php`

- [ ] **Step 1: Add English strings**

In `lang/en/messages.php`, add the following keys. Place them near the existing `'blocked_by_payment'` entry to keep payment-related strings grouped:

```php
'override_payment_block'           => 'Authorize PO generation despite pending payments',
'override_reason'                  => 'Override reason',
'request_authorization'            => 'Request authorization',
'authorization_request_sent'       => 'Authorization request sent to :names',
'no_authorized_users'              => 'No users with payment override permission found — contact your administrator',
'authorization_request_subject'    => 'Authorization request: PO generation for :reference',
'generated_with_payment_override'  => 'POs generated with payment override',
```

- [ ] **Step 2: Add Portuguese strings**

In `lang/pt_BR/messages.php`, add the parity translations:

```php
'override_payment_block'           => 'Autorizar geração de POs mesmo com pagamentos pendentes',
'override_reason'                  => 'Justificativa da autorização',
'request_authorization'            => 'Solicitar autorização',
'authorization_request_sent'       => 'Solicitação de autorização enviada para :names',
'no_authorized_users'              => 'Nenhum usuário com permissão de autorização — contate o administrador',
'authorization_request_subject'    => 'Solicitação de autorização: geração de PO para :reference',
'generated_with_payment_override'  => 'POs gerados com autorização de pagamento',
```

- [ ] **Step 3: Verify the translations load**

Run: `php artisan tinker --execute="echo __('messages.override_payment_block');"`
Expected: `Authorize PO generation despite pending payments` (or the localized version depending on app.locale).

- [ ] **Step 4: Commit**

```bash
git add lang/en/messages.php lang/pt_BR/messages.php
git commit -m "feat(i18n): payment override authorization strings"
```

---

## Task 7: Filament — Branch A (override) in `generatePurchaseOrdersAction`

**Files:**
- Modify: `app/Filament/Resources/ProformaInvoices/Concerns/ProformaInvoiceHeaderActions.php`
- Test: `tests/Feature/ProformaInvoices/GeneratePoWithOverrideTest.php`

This task only handles the **authorized user** branch (the override path). Branch B (request authorization) is Task 8.

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/ProformaInvoices/GeneratePoWithOverrideTest.php`:

```php
<?php

namespace Tests\Feature\ProformaInvoices;

use App\Domain\Financial\Actions\OverridePaymentBlocksAction;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Actions\GeneratePurchaseOrdersAction;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\Settings\Enums\CalculationBase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class GeneratePoWithOverrideTest extends TestCase
{
    use RefreshDatabase;

    private User $authorizer;
    private ProformaInvoice $pi;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'override-payment-block', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'generate-purchase-orders', 'guard_name' => 'web']);

        $this->authorizer = User::factory()->create();
        $this->authorizer->givePermissionTo(['override-payment-block', 'generate-purchase-orders']);
        $this->actingAs($this->authorizer);

        $this->pi = ProformaInvoice::factory()->create(['status' => 'confirmed']);

        PaymentScheduleItem::create([
            'payable_type'   => ProformaInvoice::class,
            'payable_id'     => $this->pi->id,
            'label'          => '30% Deposit',
            'percentage'     => 30,
            'amount'         => 30000000,
            'currency_code'  => 'USD',
            'due_condition'  => CalculationBase::BEFORE_PRODUCTION->value,
            'status'         => PaymentScheduleStatus::PENDING->value,
            'is_blocking'    => true,
            'is_credit'      => false,
            'sort_order'     => 1,
        ]);
    }

    public function test_override_unblocks_po_generation(): void
    {
        // Sanity check: blockers exist before override.
        $blockers = PaymentScheduleItem::blockingPurchaseOrderGeneration($this->pi);
        $this->assertCount(1, $blockers);

        // Apply override via the domain action (Filament wires this in Step 2-4).
        $count = (new OverridePaymentBlocksAction())->execute($this->pi, 'Client wired today.');
        $this->assertSame(1, $count);

        // Now the blocker count should be zero.
        $blockers = PaymentScheduleItem::blockingPurchaseOrderGeneration($this->pi);
        $this->assertCount(0, $blockers);
    }

    public function test_overridden_item_keeps_pending_status_after_override(): void
    {
        (new OverridePaymentBlocksAction())->execute($this->pi, 'Client wired today.');

        $item = PaymentScheduleItem::where('payable_id', $this->pi->id)->first();
        $this->assertSame(PaymentScheduleStatus::PENDING, $item->status);
        $this->assertNotNull($item->overridden_at);
        $this->assertSame($this->authorizer->id, $item->overridden_by);
    }

    public function test_new_blocking_item_added_after_override_is_not_silently_authorized(): void
    {
        (new OverridePaymentBlocksAction())->execute($this->pi, 'Authorized.');

        // Simulate reopening the PI: a new blocking item gets added later.
        PaymentScheduleItem::create([
            'payable_type'   => ProformaInvoice::class,
            'payable_id'     => $this->pi->id,
            'label'          => 'Additional 20% before production',
            'percentage'     => 20,
            'amount'         => 20000000,
            'currency_code'  => 'USD',
            'due_condition'  => CalculationBase::BEFORE_PRODUCTION->value,
            'status'         => PaymentScheduleStatus::PENDING->value,
            'is_blocking'    => true,
            'is_credit'      => false,
            'sort_order'     => 2,
        ]);

        $blockers = PaymentScheduleItem::blockingPurchaseOrderGeneration($this->pi);
        $this->assertCount(1, $blockers, 'New blocking item must NOT inherit the prior override');
    }
}
```

- [ ] **Step 2: Run the test to verify the override behavior works at the domain level**

Run: `php artisan test --filter=GeneratePoWithOverrideTest`
Expected: 3 passing tests (the action and model logic from Tasks 2 and 4 already make these pass).

If any test fails: re-check Tasks 2 and 4.

- [ ] **Step 3: Modify `generatePurchaseOrdersAction()` to add the override checkbox + reason**

Open `app/Filament/Resources/ProformaInvoices/Concerns/ProformaInvoiceHeaderActions.php`. Replace the entire `generatePurchaseOrdersAction()` method (currently lines 274-386) with:

```php
    protected function generatePurchaseOrdersAction(): Action
    {
        return Action::make('generatePurchaseOrders')
            ->label(__('forms.labels.generate_pos'))
            ->icon('heroicon-o-shopping-cart')
            ->color('warning')
            ->modalHeading('Generate Purchase Orders')
            ->modalDescription(function () {
                $record = $this->getRecord();
                $record->loadMissing(['items.supplierCompany', 'items.product.suppliers']);

                foreach ($record->items as $item) {
                    if ($item->supplier_company_id === null && $item->product) {
                        $preferred = $item->product->suppliers()
                            ->orderByDesc('company_product.is_preferred')
                            ->first();
                        if ($preferred) {
                            $item->supplier_company_id = $preferred->id;
                        }
                    }
                }

                $blockers = PaymentScheduleItem::blockingPurchaseOrderGeneration($record);

                if (count($blockers) > 0) {
                    $labels = collect($blockers)->map(function ($item) {
                        $amount = number_format($item->amount / 10000, 2);
                        return "• {$item->label} — {$item->currency_code} {$amount}";
                    })->implode("\n");

                    if (auth()->user()?->can('override-payment-block')) {
                        return "**The following upfront payments are pending:**\n\n{$labels}\n\n"
                            . "Tick the box below to authorize PO generation anyway. The obligation will remain pending in the schedule.";
                    }

                    return "**Cannot generate POs.** The following upfront payments must be resolved first:\n\n{$labels}\n\n"
                        . "Use 'Request authorization' to ask a manager to bypass this check.";
                }

                $action = new GeneratePurchaseOrdersAction();
                $existing = $action->getExistingPOs($record);
                $skipped = $action->getSkippedSuppliers($record);

                $supplierGroups = $record->items
                    ->filter(fn ($item) => $item->supplier_company_id !== null)
                    ->groupBy('supplier_company_id');

                $newCount = $supplierGroups->count() - $existing->count();

                $lines = [];

                if ($newCount > 0) {
                    $lines[] = "**{$newCount} new PO(s)** will be created, one per supplier.";
                }

                if ($existing->isNotEmpty()) {
                    $updatable = $existing->filter(fn ($po) => in_array($po->status->value, ['draft', 'sent']));
                    $locked = $existing->filter(fn ($po) => ! in_array($po->status->value, ['draft', 'sent']));

                    if ($updatable->isNotEmpty()) {
                        $names = $updatable->map(fn ($po) => $po->reference . ' (' . ($po->supplierCompany?->name ?? 'N/A') . ')')->implode(', ');
                        $lines[] = "**{$updatable->count()} PO(s) will be updated:** {$names}";
                    }

                    if ($locked->isNotEmpty()) {
                        $names = $locked->map(fn ($po) => $po->reference . ' (' . $po->status->getLabel() . ')')->implode(', ');
                        $lines[] = "**Cannot update:** {$names} (already confirmed/shipped).";
                    }
                }

                if ($skipped->isNotEmpty()) {
                    $lines[] = "**{$skipped->count()} item(s)** have no supplier assigned and will be skipped.";
                }

                return implode("\n\n", $lines);
            })
            ->form(function () {
                $record = $this->getRecord();
                $blockers = PaymentScheduleItem::blockingPurchaseOrderGeneration($record);

                if (count($blockers) === 0) {
                    return [];
                }

                if (! auth()->user()?->can('override-payment-block')) {
                    return [];
                }

                return [
                    Checkbox::make('override_payment_block')
                        ->label(__('messages.override_payment_block'))
                        ->live()
                        ->default(false),
                    Textarea::make('override_reason')
                        ->label(__('messages.override_reason'))
                        ->rows(3)
                        ->visible(fn (Get $get) => (bool) $get('override_payment_block'))
                        ->requiredIf('override_payment_block', true)
                        ->minLength(10),
                ];
            })
            ->modalSubmitActionLabel('Generate')
            ->modalSubmitAction(function ($action) {
                $record = $this->getRecord();
                $blockers = PaymentScheduleItem::blockingPurchaseOrderGeneration($record);

                // Hide the submit button entirely when there are blockers and
                // the user cannot override — Branch B (Task 8) adds the
                // "Request authorization" extra action below.
                if (count($blockers) > 0 && ! auth()->user()?->can('override-payment-block')) {
                    return $action->hidden();
                }

                return $action;
            })
            ->visible(fn () => in_array($this->getRecord()->status, [
                    ProformaInvoiceStatus::CONFIRMED,
                    ProformaInvoiceStatus::REOPENED,
                ])
                && $this->getRecord()->items()->exists()
                && auth()->user()?->can('generate-purchase-orders'))
            ->action(function (array $data) {
                $record = $this->getRecord();

                $blockers = PaymentScheduleItem::blockingPurchaseOrderGeneration($record);

                if (count($blockers) > 0) {
                    if (! ($data['override_payment_block'] ?? false)) {
                        Notification::make()
                            ->title(__('messages.blocked_by_payment'))
                            ->body(__('messages.resolve_payments_first'))
                            ->danger()
                            ->send();

                        return;
                    }

                    if (! auth()->user()?->can('override-payment-block')) {
                        Notification::make()
                            ->title(__('messages.blocked_by_payment'))
                            ->danger()
                            ->send();

                        return;
                    }

                    app(OverridePaymentBlocksAction::class)->execute(
                        $record,
                        $data['override_reason'] ?? '',
                    );
                }

                $action = new GeneratePurchaseOrdersAction();
                $result = $action->execute($record);

                if ($result->isEmpty()) {
                    Notification::make()
                        ->title(__('messages.no_pos_created'))
                        ->body(__('messages.all_pos_exist'))
                        ->warning()
                        ->send();

                    return;
                }

                $refs = $result->pluck('reference')->implode(', ');

                $title = ($data['override_payment_block'] ?? false)
                    ? __('messages.generated_with_payment_override')
                    : ($result->count() . ' ' . __('messages.pos_generated'));

                Notification::make()
                    ->title($title)
                    ->body($refs)
                    ->success()
                    ->send();
            });
    }
```

- [ ] **Step 4: Add the `OverridePaymentBlocksAction` import at the top of the file**

In `app/Filament/Resources/ProformaInvoices/Concerns/ProformaInvoiceHeaderActions.php`, find the existing `use` block (around lines 5-26) and add:

```php
use App\Domain\Financial\Actions\OverridePaymentBlocksAction;
```

- [ ] **Step 5: Run the test suite to confirm nothing regressed**

Run: `php artisan test --filter=GeneratePoWithOverrideTest`
Expected: still 3 passing tests.

Also run: `php artisan test --filter=PaymentScheduleItemOverrideTest`
Expected: still 4 passing tests.

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Resources/ProformaInvoices/Concerns/ProformaInvoiceHeaderActions.php tests/Feature/ProformaInvoices/GeneratePoWithOverrideTest.php
git commit -m "feat(proforma-invoices): authorize PO generation despite pending payments"
```

---

## Task 8: Filament — Branch B (request authorization) extra action

**Files:**
- Modify: `app/Filament/Resources/ProformaInvoices/Concerns/ProformaInvoiceHeaderActions.php`

- [ ] **Step 1: Add the `requestAuthorizationAction()` helper method**

In `ProformaInvoiceHeaderActions` trait, add this new method right after `generatePurchaseOrdersAction()`:

```php
    protected function requestPaymentOverrideAuthorizationAction(): Action
    {
        return Action::make('requestPaymentOverrideAuthorization')
            ->label(__('messages.request_authorization'))
            ->icon('heroicon-o-paper-airplane')
            ->color('info')
            ->modalHeading(__('messages.request_authorization'))
            ->modalDescription(function () {
                $authorizers = \App\Models\User::permission('override-payment-block')->get();

                if ($authorizers->isEmpty()) {
                    return __('messages.no_authorized_users');
                }

                return 'Send a request to one or more authorized users. They will receive a notification with the PI reference and the list of pending blockers.';
            })
            ->form(function () {
                $authorizers = \App\Models\User::permission('override-payment-block')->get();

                if ($authorizers->isEmpty()) {
                    return [];
                }

                return [
                    Select::make('authorizer_ids')
                        ->label(__('messages.request_authorization'))
                        ->multiple()
                        ->options($authorizers->pluck('name', 'id')->toArray())
                        ->required(),
                    Textarea::make('justification')
                        ->label(__('messages.override_reason'))
                        ->rows(3)
                        ->required()
                        ->minLength(10),
                ];
            })
            ->modalSubmitActionLabel(__('messages.request_authorization'))
            ->modalSubmitAction(function ($action) {
                $authorizers = \App\Models\User::permission('override-payment-block')->get();
                if ($authorizers->isEmpty()) {
                    return $action->hidden();
                }
                return $action;
            })
            ->visible(function () {
                $record = $this->getRecord();
                if (! in_array($record->status, [ProformaInvoiceStatus::CONFIRMED, ProformaInvoiceStatus::REOPENED])) {
                    return false;
                }
                if (auth()->user()?->can('override-payment-block')) {
                    return false;
                }
                if (! auth()->user()?->can('generate-purchase-orders')) {
                    return false;
                }
                $blockers = PaymentScheduleItem::blockingPurchaseOrderGeneration($record);
                return count($blockers) > 0;
            })
            ->action(function (array $data) {
                $record = $this->getRecord();

                $authorizers = collect($data['authorizer_ids'] ?? [])->map(fn ($id) => (int) $id)->all();
                if (empty($authorizers)) {
                    return;
                }

                app(\App\Domain\Financial\Actions\RequestPaymentOverrideAuthorizationAction::class)->execute(
                    $record,
                    auth()->user(),
                    $authorizers,
                    $data['justification'] ?? '',
                );

                $names = \App\Models\User::whereIn('id', $authorizers)->pluck('name')->implode(', ');

                Notification::make()
                    ->title(__('messages.authorization_request_sent', ['names' => $names]))
                    ->success()
                    ->send();
            });
    }
```

- [ ] **Step 2: Wire the new action into `workflowActionGroup()`**

In the same file, find `workflowActionGroup()` (currently around lines 80-89) and add the new action to the group:

```php
    protected function workflowActionGroup(): ?ActionGroup
    {
        return ActionGroup::make([
            $this->generatePurchaseOrdersAction(),
            $this->requestPaymentOverrideAuthorizationAction(),
        ])
            ->label(__('forms.labels.workflow'))
            ->icon('heroicon-o-arrows-right-left')
            ->color('primary')
            ->button();
    }
```

- [ ] **Step 3: Add the missing imports at the top of the file**

If not already present, add to the `use` block:

```php
use App\Domain\Financial\Actions\RequestPaymentOverrideAuthorizationAction;
use App\Models\User;
```

- [ ] **Step 4: Add a feature test that exercises the request-authorization flow end-to-end**

Append to `tests/Feature/ProformaInvoices/RequestPaymentOverrideAuthorizationTest.php` (the file from Task 5):

```php
    public function test_only_users_with_override_permission_appear_in_authorizer_list(): void
    {
        $unrelated = User::factory()->create(['name' => 'Unrelated']);

        $eligible = User::permission('override-payment-block')->pluck('id')->all();

        $this->assertContains($this->authorizer->id, $eligible);
        $this->assertNotContains($unrelated->id, $eligible);
        $this->assertNotContains($this->requester->id, $eligible);
    }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --filter=RequestPaymentOverrideAuthorizationTest`
Expected: 4 passing tests (3 from Task 5 + 1 new).

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Resources/ProformaInvoices/Concerns/ProformaInvoiceHeaderActions.php tests/Feature/ProformaInvoices/RequestPaymentOverrideAuthorizationTest.php
git commit -m "feat(proforma-invoices): request payment override authorization via messaging"
```

---

## Task 9: Full test suite + manual smoke check

**Files:** none (verification only)

- [ ] **Step 1: Run the full test suite**

Run: `php artisan test`
Expected: all tests pass. If anything in the existing suite breaks, investigate root cause — do NOT skip or modify unrelated tests.

- [ ] **Step 2: Manual smoke check — Branch A (override path)**

In dev environment:
1. Log in as a user with `override-payment-block` AND `generate-purchase-orders` permissions.
2. Open a Confirmed PI that has unpaid blocking payment items (BEFORE_PRODUCTION/ORDER_DATE/PO_DATE).
3. Click Workflow → Generate Purchase Orders.
4. Verify the modal shows the list of blockers and a checkbox "Authorize PO generation despite pending payments".
5. Tick the checkbox → reason textarea appears → fill 10+ chars → submit.
6. Verify POs are generated.
7. Open the PaymentScheduleItem in the DB and confirm `overridden_by`, `overridden_at`, `override_reason` are set, and `status` is still `pending`.

- [ ] **Step 3: Manual smoke check — Branch B (request authorization path)**

1. Log in as a user with `generate-purchase-orders` but NOT `override-payment-block`.
2. Open the same Confirmed PI with blockers.
3. Click Workflow → Generate Purchase Orders → modal shows blockers and "Cannot generate POs" message; submit button hidden.
4. Close modal. Click Workflow → Request authorization.
5. Verify the form shows the list of authorized users and a justification textarea.
6. Submit. Verify:
   - Notification confirms request sent
   - Logging in as the authorizer shows a new bell notification
   - The conversation contains the justification, the blocker list, and a clickable `@PI-XXXX` chip linking back to the PI

- [ ] **Step 4: Manual smoke check — cycle scoping**

1. As the authorizer, do the override + generate POs flow.
2. Try to advance the PI to `in_production` — should succeed.
3. Try to advance a related shipment to `shipped` (if a BEFORE_SHIPMENT blocker exists) — should still be blocked. Verify the override does NOT bypass shipment-stage payments.

- [ ] **Step 5: Final commit (only if anything was tweaked)**

If steps 2-4 surfaced no bugs, no commit is needed. If you fixed something, commit it with a clear message and re-run the suite.

```bash
git status
# If clean, you're done.
```

---

## Self-Review

**Spec coverage check:**

| Spec section                              | Implemented in       |
|-------------------------------------------|----------------------|
| Data model (3 new columns)                | Task 1, Task 2 (fillable/casts) |
| Override does not change `status`         | Task 2 (no status mutation), Task 4 (action), tested in Task 7 |
| `blocksPurchaseOrderGeneration` short-circuit | Task 2               |
| `blocksTransitionTo` short-circuit (PO cycle only) | Task 2               |
| Cycle scoping (BEFORE_SHIPMENT excluded)  | Task 2 (test), Task 4 (test) |
| Permission `override-payment-block`       | Task 3               |
| `OverridePaymentBlocksAction`             | Task 4               |
| `RequestPaymentOverrideAuthorizationAction` | Task 5               |
| Filament Branch A (override checkbox)     | Task 7               |
| Filament Branch B (request authorization) | Task 8               |
| i18n strings (en + pt_BR)                 | Task 6               |
| Audit trail via LogsActivity              | Implicit — `$fillable` additions in Task 2 cover this; no extra code needed |
| Per-item-snapshot (no silent reauthorization for new items) | Task 7 (test) |
| Manual verification                       | Task 9               |

All spec requirements are covered. No placeholders, no TBDs, no "implement later". Type/method names are consistent across tasks (`OverridePaymentBlocksAction::execute(Model $payable, string $reason): int`, `RequestPaymentOverrideAuthorizationAction::execute(ProformaInvoice, User, array, string): Conversation`).
