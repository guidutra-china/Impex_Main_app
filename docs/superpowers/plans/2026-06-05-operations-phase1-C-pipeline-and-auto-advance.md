# Operations Phase 1 — Sub-plan C: Declarative Pipeline + Kanban + Auto-Advance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Declare the Operations pipeline once in `OperationsPipeline` (Layer 1): the `OrderPipelineKanban` derives stage membership from it instead of hardcoded `whereIn(status, [...])`, and the single guarded path `TransitionStatusAction::execute()` runs declarative lifecycle auto-advances — specifically, confirming a ProformaInvoice advances its parent Inquiry to WON (best-effort), from any path.

**Architecture:** A `PipelineStage` value object (key, title, color, modelClass, statuses, eagerLoad) and an `AutoAdvance` value object (sourceModelClass, sourceToStatus, resolveTarget closure, targetStatus) are declared in `OperationsPipeline`. The Kanban reads stage definitions from it (keeping its bespoke per-stage card mappers). `execute()` gains a `runAutoAdvances()` step that fires after the transition commits — idempotent (guarded by `canTransitionTo`) and best-effort (wrapped in try/catch so it can never break the originating transition).

**Tech Stack:** Laravel 12, PHP 8.3, Filament 4, PHPUnit, Pint.

**Branch:** `fix/operations-phase1` (Sub-plans A and B already committed).

**Spec:** `docs/superpowers/specs/2026-06-04-operations-pipeline-foundation-design.md` (Layer 1).

---

## Context the implementer needs

- `app/Filament/Pages/OrderPipelineKanban.php` builds 6 columns via `buildInquiryColumn()`, `buildQuotingColumn()`, `buildPiIssuedColumn()`, `buildInProductionColumn()`, `buildShippingColumn()`, `buildDeliveredColumn()`. Each runs a model query with bespoke `with([...])` eager loads + `whereIn('status', [...enum instances...])` + ordering + limit, then returns `['id','title','color','count','cards'=>[...]]` with a model-specific card mapper. The current stage membership (preserve EXACTLY):
  | key | model | statuses (enum instances) | title | color | eager loads | order/limit/extra |
  |---|---|---|---|---|---|---|
  | inquiry | `Inquiry` | RECEIVED, QUOTING | Inquiry | gray | company, items | created_at desc, limit 50 |
  | quoting | `Inquiry` | QUOTED | Quoted | info | company, items | updated_at desc, limit 50 |
  | pi_issued | `ProformaInvoice` | DRAFT, SENT, CONFIRMED, FINALIZED, REOPENED | PI Issued | primary | company, items, paymentScheduleItems | updated_at desc, limit 50 |
  | in_production | `PurchaseOrder` | CONFIRMED, IN_PRODUCTION, AWAITING_SHIPMENT | In Production | warning | supplierCompany, items, paymentScheduleItems, proformaInvoice | updated_at desc, limit 50 |
  | shipping | `Shipment` | BOOKED, CUSTOMS, IN_TRANSIT | Shipping | success | company, items.purchaseOrderItem.purchaseOrder | updated_at desc, limit 50 |
  | delivered | `Shipment` | ARRIVED | Delivered (30d) | gray | company, items | updated_at desc, limit 20, AND `where('updated_at','>=',now()->subDays(30))` |
- `TransitionStatusAction::execute()` currently: early-return if same status; throw `\InvalidArgumentException` if `! canTransitionTo`; throw `TransitionBlockedException` if blockers; then `return DB::transaction(fn => status save + StateTransition log + sideEffects)`. We add an auto-advance step AFTER the transaction returns.
- `ProformaInvoice` has a non-nullable `inquiry()` belongsTo (`inquiry_id`). `Inquiry` uses `HasStateMachine`; `InquiryStatus::QUOTED->value => [WON, LOST, QUOTING]`, and `WON` is terminal. So `Inquiry::canTransitionTo(WON->value)` is true ONLY from QUOTED — from any other state (RECEIVED, QUOTING, already-WON, LOST, CANCELLED) it is false and the auto-advance is silently skipped. This is the intended, safe semantics (no forced multi-step jumps).
- PI reaches CONFIRMED via `TransitionStatusAction::execute()` from BOTH the header action (`ProformaInvoiceHeaderActions`) and the table action (`ProformaInvoicesTable` → `StatusTransitionActions`), both passing a `sideEffects` closure that runs `SyncClientProductPricesAction`. Because the auto-advance lives in `execute()`, it fires from BOTH paths with NO change to either UI site.
- The status columns are cast to their BackedEnum; Eloquent `whereIn('status', [EnumCase, ...])` converts enum instances to their values, so storing enum instances in the stage and passing them to `whereIn` preserves current behavior exactly.

---

## File Structure

| File | Responsibility | Action |
|---|---|---|
| `app/Domain/Operations/PipelineStage.php` | Value object: one pipeline stage + its base query | Create |
| `app/Domain/Operations/AutoAdvance.php` | Value object: one lifecycle auto-advance rule | Create |
| `app/Domain/Operations/OperationsPipeline.php` | Declares `stages()`, `stage()`, `autoAdvances()`, `autoAdvancesFor()` | Create |
| `app/Filament/Pages/OrderPipelineKanban.php` | Derive each column's model/statuses/title/color/eager-loads from the pipeline | Modify |
| `app/Domain/Infrastructure/Actions/TransitionStatusAction.php` | Run auto-advances after the transition (best-effort) | Modify |
| `tests/Feature/Operations/OperationsPipelineTest.php` | Pipeline stage/auto-advance declarations | Create (Test) |
| `tests/Feature/Operations/AutoAdvanceTest.php` | Auto-advance behavior (action-level + table-path integration) | Create (Test) |

---

## Task 1: PipelineStage + OperationsPipeline::stages()

**Files:**
- Create: `app/Domain/Operations/PipelineStage.php`
- Create: `app/Domain/Operations/OperationsPipeline.php`
- Test: `tests/Feature/Operations/OperationsPipelineTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Operations/OperationsPipelineTest.php`:

```php
<?php

namespace Tests\Feature\Operations;

use App\Domain\Inquiries\Enums\InquiryStatus;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Operations\OperationsPipeline;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Tests\TestCase;

class OperationsPipelineTest extends TestCase
{
    public function test_declares_six_stages_in_order(): void
    {
        $keys = array_map(fn ($s) => $s->key, OperationsPipeline::stages());

        $this->assertSame(
            ['inquiry', 'quoting', 'pi_issued', 'in_production', 'shipping', 'delivered'],
            $keys,
        );
    }

    public function test_inquiry_stage_carries_model_and_statuses(): void
    {
        $stage = OperationsPipeline::stage('inquiry');

        $this->assertSame(Inquiry::class, $stage->modelClass);
        $this->assertEqualsCanonicalizing(
            [InquiryStatus::RECEIVED, InquiryStatus::QUOTING],
            $stage->statuses,
        );
        $this->assertSame('Inquiry', $stage->title);
        $this->assertSame('gray', $stage->color);
    }

    public function test_pi_issued_stage_query_filters_by_its_statuses(): void
    {
        ProformaInvoice::factory()->create(['status' => ProformaInvoiceStatus::DRAFT->value]);
        ProformaInvoice::factory()->create(['status' => ProformaInvoiceStatus::CANCELLED->value]);

        $stage = OperationsPipeline::stage('pi_issued');
        $refs = $stage->query()->pluck('status')->map(fn ($s) => $s->value)->all();

        $this->assertContains(ProformaInvoiceStatus::DRAFT->value, $refs);
        $this->assertNotContains(ProformaInvoiceStatus::CANCELLED->value, $refs);
    }

    public function test_stage_throws_for_unknown_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OperationsPipeline::stage('nope');
    }
}
```

> This test needs `RefreshDatabase` for the third case — add `use Illuminate\Foundation\Testing\RefreshDatabase;` and `use RefreshDatabase;` to the class.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=OperationsPipelineTest`
Expected: FAIL — `OperationsPipeline` / `PipelineStage` classes do not exist.

- [ ] **Step 3: Create `PipelineStage`**

Create `app/Domain/Operations/PipelineStage.php`:

```php
<?php

namespace App\Domain\Operations;

use Illuminate\Database\Eloquent\Builder;

/**
 * One stage of the end-to-end Operations pipeline. Declares which model and
 * which statuses constitute the stage; `query()` returns the base query so
 * consumers (Kanban, future cross-feature locks) share one definition of
 * stage membership.
 */
final class PipelineStage
{
    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @param  array<\BackedEnum>  $statuses
     * @param  array<string>  $eagerLoad
     */
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly string $color,
        public readonly string $modelClass,
        public readonly array $statuses,
        public readonly array $eagerLoad = [],
    ) {}

    public function query(): Builder
    {
        return ($this->modelClass)::query()
            ->with($this->eagerLoad)
            ->whereIn('status', $this->statuses);
    }
}
```

- [ ] **Step 4: Create `OperationsPipeline`**

Create `app/Domain/Operations/OperationsPipeline.php`:

```php
<?php

namespace App\Domain\Operations;

use App\Domain\Inquiries\Enums\InquiryStatus;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;

class OperationsPipeline
{
    /**
     * The end-to-end Operations pipeline, declared once. Single source of truth
     * for stage membership (consumed by OrderPipelineKanban).
     *
     * @return array<PipelineStage>
     */
    public static function stages(): array
    {
        return [
            new PipelineStage('inquiry', 'Inquiry', 'gray', Inquiry::class,
                [InquiryStatus::RECEIVED, InquiryStatus::QUOTING], ['company', 'items']),
            new PipelineStage('quoting', 'Quoted', 'info', Inquiry::class,
                [InquiryStatus::QUOTED], ['company', 'items']),
            new PipelineStage('pi_issued', 'PI Issued', 'primary', ProformaInvoice::class, [
                ProformaInvoiceStatus::DRAFT,
                ProformaInvoiceStatus::SENT,
                ProformaInvoiceStatus::CONFIRMED,
                ProformaInvoiceStatus::FINALIZED,
                ProformaInvoiceStatus::REOPENED,
            ], ['company', 'items', 'paymentScheduleItems']),
            new PipelineStage('in_production', 'In Production', 'warning', PurchaseOrder::class, [
                PurchaseOrderStatus::CONFIRMED,
                PurchaseOrderStatus::IN_PRODUCTION,
                PurchaseOrderStatus::AWAITING_SHIPMENT,
            ], ['supplierCompany', 'items', 'paymentScheduleItems', 'proformaInvoice']),
            new PipelineStage('shipping', 'Shipping', 'success', Shipment::class, [
                ShipmentStatus::BOOKED,
                ShipmentStatus::CUSTOMS,
                ShipmentStatus::IN_TRANSIT,
            ], ['company', 'items.purchaseOrderItem.purchaseOrder']),
            new PipelineStage('delivered', 'Delivered (30d)', 'gray', Shipment::class,
                [ShipmentStatus::ARRIVED], ['company', 'items']),
        ];
    }

    public static function stage(string $key): PipelineStage
    {
        foreach (self::stages() as $stage) {
            if ($stage->key === $key) {
                return $stage;
            }
        }

        throw new \InvalidArgumentException("Unknown pipeline stage: {$key}");
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=OperationsPipelineTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Run Pint**

Run: `vendor/bin/pint app/Domain/Operations tests/Feature/Operations`
Expected: pass.

- [ ] **Step 7: Commit**

```bash
git add app/Domain/Operations/PipelineStage.php app/Domain/Operations/OperationsPipeline.php tests/Feature/Operations/OperationsPipelineTest.php
git commit -m "feat(operations): declarative OperationsPipeline stage definitions

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: OrderPipelineKanban derives stages from the pipeline

**Files:**
- Modify: `app/Filament/Pages/OrderPipelineKanban.php`

Refactor each `buildXColumn()` to source `model`/`statuses`/`title`/`color`/`eagerLoad` from `OperationsPipeline::stage(<key>)`, keeping the bespoke ordering/limit and card mappers. Goal: ZERO visual change — same columns, same cards, same order.

- [ ] **Step 1: Check for an existing Kanban test (baseline)**

Run: `php artisan test --filter=OrderPipelineKanban`
Record whether any test exists and passes. (If none exists, Task 2 Step 6 adds a smoke test.)

- [ ] **Step 2: Refactor each column to use the stage's query + metadata**

In `app/Filament/Pages/OrderPipelineKanban.php`, add the import:
```php
use App\Domain\Operations\OperationsPipeline;
```
Rewrite each `buildXColumn()` so the query and the `id`/`title`/`color` come from the stage. Example for the inquiry column (apply the same shape to all six):
```php
    protected function buildInquiryColumn(): array
    {
        $stage = OperationsPipeline::stage('inquiry');
        $inquiries = $stage->query()
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        return [
            'id' => $stage->key,
            'title' => $stage->title,
            'color' => $stage->color,
            'count' => $inquiries->count(),
            'cards' => $inquiries->map(fn (Inquiry $inquiry) => [
                // ... UNCHANGED bespoke card mapper ...
            ])->toArray(),
        ];
    }
```
Apply the analogous change to `buildQuotingColumn` (key `quoting`, `orderBy('updated_at','desc')->limit(50)`), `buildPiIssuedColumn` (key `pi_issued`, `updated_at desc, limit 50`), `buildInProductionColumn` (key `in_production`, `updated_at desc, limit 50`), `buildShippingColumn` (key `shipping`, `updated_at desc, limit 50`), and `buildDeliveredColumn` (key `delivered`, `->where('updated_at','>=',now()->subDays(30))->orderBy('updated_at','desc')->limit(20)`).

Keep EACH column's existing card-mapper body verbatim (the `'value'`, `'payment_progress'`, `'has_overdue'`, `'eta'`, `'subtitle'`, `'days_since_update'` fields, etc.). Remove now-unused status-enum imports ONLY if they are no longer referenced anywhere in the file (the card mappers may still reference some, e.g. `PaymentScheduleStatus::OVERDUE`, `ShipmentStatus` — keep those). Do NOT remove `Money`, resource imports, or model imports used by the mappers.

- [ ] **Step 3: Manual diff self-check**

Re-read the diff: confirm each column now pulls statuses from the pipeline (no remaining `whereIn('status', [...])` literal in the Kanban), and every card-mapper field is byte-for-byte what it was. Confirm the delivered column still has the `subDays(30)` filter and `limit(20)`, and the quoting column still uses the QUOTED-only status set via the `quoting` stage.

- [ ] **Step 4: Run the full suite (catch any breakage)**

Run: `php artisan test`
Expected: all green. Investigate any failure.

- [ ] **Step 5: Run Pint**

Run: `vendor/bin/pint app/Filament/Pages/OrderPipelineKanban.php`
Expected: pass.

- [ ] **Step 6: Add a Kanban smoke test**

Create `tests/Feature/Operations/OrderPipelineKanbanTest.php`:

```php
<?php

namespace Tests\Feature\Operations;

use App\Domain\Inquiries\Enums\InquiryStatus;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Filament\Pages\OrderPipelineKanban;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderPipelineKanbanTest extends TestCase
{
    use RefreshDatabase;

    public function test_columns_are_built_from_the_pipeline_and_records_land_in_the_right_stage(): void
    {
        $inquiry = Inquiry::factory()->create(['status' => InquiryStatus::RECEIVED->value]);
        $pi = ProformaInvoice::factory()->create(['status' => ProformaInvoiceStatus::DRAFT->value]);

        $columns = (new OrderPipelineKanban)->getColumns();

        $this->assertSame(
            ['inquiry', 'quoting', 'pi_issued', 'in_production', 'shipping', 'delivered'],
            array_map(fn ($c) => $c['id'], $columns),
        );

        $inquiryCol = collect($columns)->firstWhere('id', 'inquiry');
        $piCol = collect($columns)->firstWhere('id', 'pi_issued');

        $this->assertContains($inquiry->id, array_column($inquiryCol['cards'], 'id'));
        $this->assertContains($pi->id, array_column($piCol['cards'], 'id'));
    }
}
```

> Executor note: if `Inquiry::factory()` does not exist, create a minimal one following `ProformaInvoiceFactory`/`PurchaseOrderFactory` conventions (only if needed by this test), or build the Inquiry via `Inquiry::create([...])` mirroring the existing `ProductionScheduleGridTest::setUp()` inquiry creation. Adjust so the test compiles and the two records land in their stages.

- [ ] **Step 7: Run the smoke test**

Run: `php artisan test --filter=OrderPipelineKanbanTest`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Filament/Pages/OrderPipelineKanban.php tests/Feature/Operations/OrderPipelineKanbanTest.php
# include database/factories/InquiryFactory.php if you created one
git commit -m "refactor(operations): OrderPipelineKanban derives stages from OperationsPipeline

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: AutoAdvance value object + execute() hook

**Files:**
- Create: `app/Domain/Operations/AutoAdvance.php`
- Modify: `app/Domain/Operations/OperationsPipeline.php` (add `autoAdvances()` + `autoAdvancesFor()`)
- Modify: `app/Domain/Infrastructure/Actions/TransitionStatusAction.php` (add `runAutoAdvances()` after the transaction)
- Test: `tests/Feature/Operations/AutoAdvanceTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Operations/AutoAdvanceTest.php`:

```php
<?php

namespace Tests\Feature\Operations;

use App\Domain\Infrastructure\Actions\TransitionStatusAction;
use App\Domain\Inquiries\Enums\InquiryStatus;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoAdvanceTest extends TestCase
{
    use RefreshDatabase;

    private function piWithInquiry(InquiryStatus $inquiryStatus, ProformaInvoiceStatus $piStatus): ProformaInvoice
    {
        $inquiry = Inquiry::factory()->create(['status' => $inquiryStatus->value]);

        return ProformaInvoice::factory()->create([
            'inquiry_id' => $inquiry->id,
            'status' => $piStatus->value,
        ]);
    }

    public function test_confirming_pi_advances_quoted_inquiry_to_won(): void
    {
        $pi = $this->piWithInquiry(InquiryStatus::QUOTED, ProformaInvoiceStatus::SENT);

        app(TransitionStatusAction::class)->execute($pi, ProformaInvoiceStatus::CONFIRMED);

        $this->assertSame(InquiryStatus::WON->value, $pi->inquiry->fresh()->status->value);
    }

    public function test_confirming_pi_does_not_touch_a_non_quoted_inquiry(): void
    {
        // RECEIVED cannot transition straight to WON; auto-advance is skipped silently.
        $pi = $this->piWithInquiry(InquiryStatus::RECEIVED, ProformaInvoiceStatus::SENT);

        app(TransitionStatusAction::class)->execute($pi, ProformaInvoiceStatus::CONFIRMED);

        $this->assertSame(InquiryStatus::RECEIVED->value, $pi->inquiry->fresh()->status->value);
        // The PI itself still confirmed (auto-advance is best-effort, never blocks).
        $this->assertSame(ProformaInvoiceStatus::CONFIRMED->value, $pi->fresh()->status->value);
    }

    public function test_pi_confirms_even_if_inquiry_already_won(): void
    {
        $pi = $this->piWithInquiry(InquiryStatus::WON, ProformaInvoiceStatus::SENT);

        app(TransitionStatusAction::class)->execute($pi, ProformaInvoiceStatus::CONFIRMED);

        $this->assertSame(ProformaInvoiceStatus::CONFIRMED->value, $pi->fresh()->status->value);
        $this->assertSame(InquiryStatus::WON->value, $pi->inquiry->fresh()->status->value);
    }
}
```

> Executor note: confirm `Inquiry::factory()` exists; if not, create a minimal `database/factories/InquiryFactory.php` (Inquiry needs at least `reference`, `company_id`, `status`, `source`, `currency_code` per existing tests — see `ProductionScheduleGridTest::setUp()`), or build via `Inquiry::create([...])` in the helper. `ProformaInvoice::factory()` already exists; ensure passing `inquiry_id` overrides the factory default so the PI points at our inquiry.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AutoAdvanceTest`
Expected: FAIL — confirming a PI does not yet advance the inquiry (first test asserts WON, gets QUOTED).

- [ ] **Step 3: Create `AutoAdvance`**

Create `app/Domain/Operations/AutoAdvance.php`:

```php
<?php

namespace App\Domain\Operations;

use Illuminate\Database\Eloquent\Model;

/**
 * A declarative lifecycle automation: when a $sourceModelClass transitions to
 * $sourceToStatus, advance the model returned by ($resolveTarget) to
 * $targetStatus. Applied centrally by TransitionStatusAction (best-effort).
 */
final class AutoAdvance
{
    /**
     * @param  class-string<Model>  $sourceModelClass
     * @param  \Closure(Model): ?Model  $resolveTarget
     */
    public function __construct(
        public readonly string $sourceModelClass,
        public readonly string $sourceToStatus,
        public readonly \Closure $resolveTarget,
        public readonly string $targetStatus,
    ) {}
}
```

- [ ] **Step 4: Add `autoAdvances()` + `autoAdvancesFor()` to `OperationsPipeline`**

Add to `app/Domain/Operations/OperationsPipeline.php` (add the imports `use App\Domain\Infrastructure\Traits\HasStateMachine;`? not needed; add `use Illuminate\Database\Eloquent\Model;`):

```php
    /**
     * Declarative lifecycle auto-advances applied by TransitionStatusAction
     * after a successful transition.
     *
     * @return array<AutoAdvance>
     */
    public static function autoAdvances(): array
    {
        return [
            // Confirming a Proforma Invoice marks its originating Inquiry as won.
            new AutoAdvance(
                ProformaInvoice::class,
                ProformaInvoiceStatus::CONFIRMED->value,
                fn (ProformaInvoice $pi) => $pi->inquiry,
                InquiryStatus::WON->value,
            ),
        ];
    }

    /**
     * @return array<AutoAdvance>
     */
    public static function autoAdvancesFor(Model $model, string $toStatus): array
    {
        return array_values(array_filter(
            self::autoAdvances(),
            fn (AutoAdvance $a) => $model instanceof $a->sourceModelClass && $a->sourceToStatus === $toStatus,
        ));
    }
```

- [ ] **Step 5: Hook `runAutoAdvances()` into `execute()`**

In `app/Domain/Infrastructure/Actions/TransitionStatusAction.php`, add imports:
```php
use App\Domain\Operations\OperationsPipeline;
```
Change the tail of `execute()` from `return DB::transaction(...);` to capture the result, run auto-advances, then return:
```php
        $result = DB::transaction(function () use (...) {
            // ... unchanged body ...
        });

        $this->runAutoAdvances($model, $toStatusValue);

        return $result;
```
Add the private method:
```php
    /**
     * Apply declarative pipeline auto-advances after a successful transition.
     * Best-effort: each is guarded by canTransitionTo and wrapped in try/catch
     * so a downstream failure can never break the originating transition.
     */
    protected function runAutoAdvances(Model $model, string $toStatusValue): void
    {
        foreach (OperationsPipeline::autoAdvancesFor($model, $toStatusValue) as $advance) {
            try {
                $target = ($advance->resolveTarget)($model);

                if ($target !== null && $target->canTransitionTo($advance->targetStatus)) {
                    $this->execute($target, $advance->targetStatus, notes: 'Auto-advance from '.class_basename($model).' → '.$toStatusValue);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
```

> Placement note: `runAutoAdvances` runs AFTER the source transaction commits, so the source transition is durable before any auto-advance is attempted (true best-effort). The recursive `execute()` for the target opens its own transaction and checks its own `autoAdvancesFor` — Inquiry→WON has no registered advance, so it terminates. No cycle exists in `autoAdvances()`.

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=AutoAdvanceTest`
Expected: PASS (3 tests).

- [ ] **Step 7: Run Pint**

Run: `vendor/bin/pint app/Domain/Operations app/Domain/Infrastructure/Actions/TransitionStatusAction.php tests/Feature/Operations`
Expected: pass.

- [ ] **Step 8: Commit**

```bash
git add app/Domain/Operations/AutoAdvance.php app/Domain/Operations/OperationsPipeline.php app/Domain/Infrastructure/Actions/TransitionStatusAction.php tests/Feature/Operations/AutoAdvanceTest.php
# include database/factories/InquiryFactory.php if you created it
git commit -m "feat(operations): auto-advance Inquiry to WON when its PI is confirmed

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: Integration — auto-advance fires from the table path + full suite

**Files:**
- Test: `tests/Feature/Operations/AutoAdvanceTest.php` (add a Livewire table-path case)

This task proves the centralization payoff: the auto-advance fires regardless of which UI path confirms the PI, with no per-UI wiring.

- [ ] **Step 1: Add a table-path integration test**

Add to `tests/Feature/Operations/AutoAdvanceTest.php` (add imports `use App\Filament\Resources\ProformaInvoices\Pages\ListProformaInvoices;`, `use App\Models\User;`, `use Filament\Facades\Filament;`, `use Illuminate\Support\Facades\Gate;`, `use Livewire\Livewire;`):

```php
    public function test_confirming_pi_via_table_action_also_advances_the_inquiry(): void
    {
        $admin = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $admin->id ? true : null);
        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');

        $pi = $this->piWithInquiry(InquiryStatus::QUOTED, ProformaInvoiceStatus::SENT);

        Livewire::test(ListProformaInvoices::class)
            ->callTableAction('transition_to_confirmed', $pi);

        $this->assertSame(ProformaInvoiceStatus::CONFIRMED->value, $pi->fresh()->status->value);
        $this->assertSame(InquiryStatus::WON->value, $pi->inquiry->fresh()->status->value);
    }
```

> Executor note: the table confirm action id is `transition_to_confirmed` (from `StatusTransitionActions`). If the PI list table action id differs, locate it (grep `transition_to_confirmed` / the PI table) and use the correct id. The `SyncClientProductPricesAction` sideEffects must still run without error — if the action needs extra fixture (e.g. PI items/products), add the minimum so the confirm succeeds; the focus is that the inquiry reaches WON via this path.

- [ ] **Step 2: Run the integration test**

Run: `php artisan test --filter=test_confirming_pi_via_table_action_also_advances_the_inquiry`
Expected: PASS — confirms the auto-advance fires from the table path through the shared `execute()`.

- [ ] **Step 3: Run the full suite**

Run: `composer test`
Expected: all green, 0 failures (prior 562 + the new Operations pipeline/auto-advance tests). Investigate any regression — especially any existing test that confirms a PI and now also moves an Inquiry (a previously-QUOTED inquiry becoming WON is the intended new behavior; update such a test's expectation only if it asserts the inquiry stayed QUOTED).

- [ ] **Step 4: Run Pint**

Run: `vendor/bin/pint tests/Feature/Operations`
Expected: pass.

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/Operations/AutoAdvanceTest.php
git commit -m "test(operations): auto-advance fires via PI table confirm path

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Self-Review

**Spec coverage (Layer 1):**
- Declarative `OperationsPipeline` with `PipelineStage` value object → Task 1. ✓
- Kanban derives stage membership from the pipeline (single source of truth, no visual change) → Task 2. ✓
- `AutoAdvance` + central hook in `execute()`; PI CONFIRMED ⇒ Inquiry WON, best-effort, from any path → Tasks 3–4. ✓
- Best-effort (try/catch) + idempotent (canTransitionTo) + terminates (no cycle) → Task 3. ✓
- Fires from header AND table with no per-UI change (the centralization payoff) → Task 4. ✓

**Placeholder scan:** none — concrete code + commands throughout. Executor notes are verification/fallback instructions (factory existence, action id), not placeholders.

**Type consistency:** `PipelineStage` properties (`key/title/color/modelClass/statuses/eagerLoad`) are used consistently in `OperationsPipeline::stages()` and the Kanban. `AutoAdvance` (`sourceModelClass/sourceToStatus/resolveTarget/targetStatus`) matches `autoAdvancesFor()` and `runAutoAdvances()`. `autoAdvancesFor(Model, string)` and `runAutoAdvances(Model, string)` agree. `execute()` still returns `Model`.

**Risk:** The auto-advance changes behavior — confirming a PI now moves a QUOTED inquiry to WON. Existing tests that confirm a PI may need their inquiry-status expectation updated (Task 4 Step 3 calls this out). The Kanban refactor is behavior-preserving (same status sets, bespoke mappers kept); the full suite + smoke test guard it. `execute()` recursion terminates (Inquiry→WON has no registered auto-advance).

## Out of scope (later sub-plans / follow-ups)
Routing `ExecuteShipmentPlanAction` and `EditPurchaseOrder::beforeSave()` through the central path (Sub-plan A follow-ups); SupplierAudit + ProjectDevelopment state machines; cross-feature prerequisite locks (Layer 4) and other later layers. Multi-step inquiry jumps (e.g. forcing QUOTING→WON) are intentionally NOT done — the auto-advance only fires when `canTransitionTo(WON)` is already true (i.e. the inquiry is QUOTED).
