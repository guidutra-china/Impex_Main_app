# Operations Phase 1 — Sub-plan B: ProductionSchedule State Machine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bring `ProductionSchedule` under the `HasStateMachine` trait (Layer 2 pilot) so its status changes flow through the single guarded path `TransitionStatusAction::execute()` — gaining transition validation and `StateTransition` audit logging — instead of the 4 raw `->update(['status' => ...])` calls scattered across Livewire components.

**Architecture:** Add `allowedTransitions()` to the model and route each raw status mutation through `transitionTo()` / `execute()`, preserving the auxiliary field writes (`submitted_at`, `approved_by`, `approved_at`, `approval_notes`) via the `execute()` `sideEffects` closure (atomic, inside the transaction). Existing Livewire component tests are the regression net.

**Tech Stack:** Laravel 12, PHP 8.3, Filament 4, Livewire 3, PHPUnit, Pint.

**Branch:** `fix/operations-phase1` (Sub-plan A already committed).

**Spec:** `docs/superpowers/specs/2026-06-04-operations-pipeline-foundation-design.md` (Layer 2).

---

## Context the implementer needs

- `ProductionSchedule` (`app/Domain/Planning/Models/ProductionSchedule.php`) currently uses `HasFactory, HasReference, LogsActivity` and has `newFactory()`. `status` is cast to `ProductionScheduleStatus`.
- `ProductionScheduleStatus` (`app/Domain/Planning/Enums/ProductionScheduleStatus.php`) ALREADY implements `HasLabel, HasColor, HasIcon` (no enum change needed — the spec's "align to interfaces" note is obsolete). Cases: `Draft=draft, PendingApproval=pending_approval, Approved=approved, Rejected=rejected, Completed=completed`. It also has `canBeEditedBySupplier()` / `canRequestEdit()` — leave these untouched (orthogonal to the state machine).
- `HasStateMachine` (`app/Domain/Infrastructure/Traits/HasStateMachine.php`) requires `abstract public static function allowedTransitions(): array`. It provides `transitionTo(string|\BackedEnum $toStatus, ?string $notes = null, array $metadata = [])` which delegates to `app(TransitionStatusAction::class)->execute(...)`. `execute()` validates via `canTransitionTo()` (throws `\InvalidArgumentException` on an invalid transition), writes status + a `StateTransition` audit row inside a `DB::transaction`, then runs an optional `sideEffects(callable)` closure inside that same transaction. `ProductionSchedule` declares NO blockers, so it inherits the default `getTransitionBlockers()` returning `[]` (no `TransitionBlockedException` possible).
- The 4 real status transitions and their sites:
  | Site | Transition | Aux fields written alongside status |
  |---|---|---|
  | `app/Livewire/SupplierPortal/ProductionScheduleGrid.php` `submit()` | Draft/Rejected → PendingApproval | `submitted_at=now()`, `approved_by=null`, `approved_at=null` |
  | `app/Livewire/Portal/ScheduleApprovalWidget.php` `approve()` | PendingApproval → Approved | `approved_by=auth()->id()`, `approved_at=now()` |
  | `app/Livewire/Portal/ScheduleApprovalWidget.php` `reject()` | PendingApproval → Rejected | `approved_by=auth()->id()`, `approved_at=now()`, `approval_notes=$this->approvalNote` |
  | `app/Livewire/Admin/ProductionActualsGrid.php` `checkAutoComplete()` (private, via `updateActual()`) | Approved → Completed | (none) |
  | `app/Filament/SupplierPortal/Resources/ProductionScheduleResource/Pages/CreateProductionSchedule.php` | sets initial `Draft` on create | NOT a transition — leave as-is |
- Existing Livewire tests that assert these outcomes (the regression net): `tests/Feature/Livewire/Portal/ScheduleApprovalWidgetTest.php` (approve→Approved, reject→Rejected, reject-from-non-PendingApproval no-op), `tests/Feature/Livewire/SupplierPortal/ProductionScheduleGridTest.php` (submit→PendingApproval), `tests/Feature/Livewire/Admin/ProductionActualsGridTest.php` (auto→Completed, partial→stays).
- `ProductionScheduleFactory` exists.
- The existing approve/reject methods have an `if ($this->schedule->status !== ProductionScheduleStatus::PendingApproval) return;` guard. KEEP these guards — they make an out-of-state call a silent no-op (asserted by the existing test), whereas the state machine would otherwise throw `\InvalidArgumentException`.

---

## File Structure

| File | Responsibility | Action |
|---|---|---|
| `app/Domain/Planning/Models/ProductionSchedule.php` | Adopt `HasStateMachine`, declare `allowedTransitions()` | Modify |
| `tests/Feature/Operations/ProductionScheduleStateMachineTest.php` | Model-level transition + audit-log tests | Create (Test) |
| `app/Livewire/Portal/ScheduleApprovalWidget.php` | Route `approve()`/`reject()` through `execute()` | Modify |
| `app/Livewire/SupplierPortal/ProductionScheduleGrid.php` | Route `submit()` through `execute()` | Modify |
| `app/Livewire/Admin/ProductionActualsGrid.php` | Route auto-complete through `transitionTo()` | Modify |

---

## Task 1: Adopt HasStateMachine + allowedTransitions (+ model-level tests)

**Files:**
- Modify: `app/Domain/Planning/Models/ProductionSchedule.php`
- Test: `tests/Feature/Operations/ProductionScheduleStateMachineTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Operations/ProductionScheduleStateMachineTest.php`:

```php
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

        // Each transition wrote a StateTransition audit row (polymorphic on 'model').
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
```

> **Executor note:** confirm the audit table name is `state_transitions` (read the `StateTransition` model's `$table` / migration). If it differs, fix the `assertDatabaseHas` table name. The `stateTransitions()` relation is provided by `HasStateMachine`.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ProductionScheduleStateMachineTest`
Expected: FAIL — `ProductionSchedule` has no `transitionTo()` / `allowedTransitions()` yet (Error: method/trait missing).

- [ ] **Step 3: Add the trait + import + allowedTransitions**

In `app/Domain/Planning/Models/ProductionSchedule.php`:

1. Add the import near the other `use` statements at the top:
```php
use App\Domain\Infrastructure\Traits\HasStateMachine;
use App\Domain\Planning\Enums\ProductionScheduleStatus;
```
(Verify `ProductionScheduleStatus` isn't already imported; if it is, don't duplicate.)

2. Add `HasStateMachine` to the trait `use` line inside the class:
```php
    use HasFactory, HasReference, HasStateMachine, LogsActivity;
```

3. Add the `allowedTransitions()` method (place it near the top of the class body, e.g. right after `newFactory()`):
```php
    public static function allowedTransitions(): array
    {
        return [
            ProductionScheduleStatus::Draft->value => [ProductionScheduleStatus::PendingApproval->value],
            ProductionScheduleStatus::Rejected->value => [ProductionScheduleStatus::PendingApproval->value],
            ProductionScheduleStatus::PendingApproval->value => [
                ProductionScheduleStatus::Approved->value,
                ProductionScheduleStatus::Rejected->value,
            ],
            ProductionScheduleStatus::Approved->value => [ProductionScheduleStatus::Completed->value],
            ProductionScheduleStatus::Completed->value => [],
        ];
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ProductionScheduleStateMachineTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Run Pint**

Run: `vendor/bin/pint app/Domain/Planning/Models/ProductionSchedule.php tests/Feature/Operations`
Expected: pass.

- [ ] **Step 6: Commit**

```bash
git add app/Domain/Planning/Models/ProductionSchedule.php tests/Feature/Operations/ProductionScheduleStateMachineTest.php
git commit -m "feat(operations): ProductionSchedule adopts HasStateMachine

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Route ScheduleApprovalWidget approve()/reject() through the guarded path

**Files:**
- Modify: `app/Livewire/Portal/ScheduleApprovalWidget.php`
- Regression net: `tests/Feature/Livewire/Portal/ScheduleApprovalWidgetTest.php` (existing — do not rewrite)

- [ ] **Step 1: Baseline — run the existing widget test (must pass first)**

Run: `php artisan test --filter=ScheduleApprovalWidgetTest`
Expected: PASS. This is the regression net; record that it's green before changing code.

- [ ] **Step 2: Refactor `approve()`**

In `app/Livewire/Portal/ScheduleApprovalWidget.php`, add the import at the top:
```php
use App\Domain\Infrastructure\Actions\TransitionStatusAction;
```
Keep the existing `if ($this->schedule->status !== ProductionScheduleStatus::PendingApproval) { return; }` guard. Replace the `$this->schedule->update([...])` call in `approve()` with:
```php
        app(TransitionStatusAction::class)->execute(
            $this->schedule,
            ProductionScheduleStatus::Approved->value,
            sideEffects: function (ProductionSchedule $schedule): void {
                $schedule->update([
                    'approved_by' => auth()->id(),
                    'approved_at' => now(),
                ]);
            },
        );
        $this->schedule->refresh();
```
(Add `use App\Domain\Planning\Models\ProductionSchedule;` if not already imported, for the closure type-hint.)

- [ ] **Step 3: Refactor `reject()`**

Keep the `if (... !== PendingApproval) return;` guard and the `$this->validate([...])` call. Replace the `$this->schedule->update([...])` in `reject()` with:
```php
        app(TransitionStatusAction::class)->execute(
            $this->schedule,
            ProductionScheduleStatus::Rejected->value,
            sideEffects: function (ProductionSchedule $schedule): void {
                $schedule->update([
                    'approved_by' => auth()->id(),
                    'approved_at' => now(),
                    'approval_notes' => $this->approvalNote,
                ]);
            },
        );
        $this->schedule->refresh();
```
Note: the closure captures `$this->approvalNote` — use `use ($/* captured via $this */)`; since it's an arrow-context closure referencing `$this`, a normal `function () { ... $this->approvalNote ... }` closure binds `$this` automatically in a Livewire component method. Verify `approval_notes` resolves to the component's `approvalNote` property value.

- [ ] **Step 4: Run the existing widget test — must still pass**

Run: `php artisan test --filter=ScheduleApprovalWidgetTest`
Expected: PASS (approve→Approved, reject→Rejected, no-op-from-wrong-status all green). Also confirm a `StateTransition` is now logged — optionally add one assertion to the existing approve test: `$this->assertSame(1, $schedule->fresh()->stateTransitions()->count());` (only if it doesn't conflict with the file's style; otherwise rely on Task 1's audit coverage).

- [ ] **Step 5: Run Pint**

Run: `vendor/bin/pint app/Livewire/Portal/ScheduleApprovalWidget.php`
Expected: pass.

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Portal/ScheduleApprovalWidget.php tests/Feature/Livewire/Portal/ScheduleApprovalWidgetTest.php
git commit -m "refactor(operations): route ProductionSchedule approve/reject through guarded transition

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Route ProductionScheduleGrid submit() through the guarded path

**Files:**
- Modify: `app/Livewire/SupplierPortal/ProductionScheduleGrid.php`
- Regression net: `tests/Feature/Livewire/SupplierPortal/ProductionScheduleGridTest.php` (existing)

- [ ] **Step 1: Baseline — run the existing grid test**

Run: `php artisan test --filter=ProductionScheduleGridTest`
Expected: PASS. Record green before changing code.

- [ ] **Step 2: Refactor `submit()`**

In `app/Livewire/SupplierPortal/ProductionScheduleGrid.php`, add the import:
```php
use App\Domain\Infrastructure\Actions\TransitionStatusAction;
```
Replace the `$this->schedule->update(['status' => ProductionScheduleStatus::PendingApproval, 'submitted_at' => now(), 'approved_by' => null, 'approved_at' => null]);` block (after the validation `if (! empty($errors))` early-return) with:
```php
        app(TransitionStatusAction::class)->execute(
            $this->schedule,
            ProductionScheduleStatus::PendingApproval->value,
            sideEffects: function (ProductionSchedule $schedule): void {
                $schedule->update([
                    'submitted_at' => now(),
                    'approved_by' => null,
                    'approved_at' => null,
                ]);
            },
        );
        $this->schedule->refresh();
```
(Add `use App\Domain\Planning\Models\ProductionSchedule;` for the type-hint if not present.)

Safety note: `submit()` is only reachable in editing mode, which `canEdit()` restricts to `Draft`/`Rejected` (both of which allow → `PendingApproval`), so `execute()` will not throw an invalid-transition error in practice. Do NOT add a try/catch unless a baseline run shows a status the map doesn't allow.

- [ ] **Step 3: Run the existing grid test — must still pass**

Run: `php artisan test --filter=ProductionScheduleGridTest`
Expected: PASS (submit→PendingApproval green; Approved-schedule-cannot-edit still green).

- [ ] **Step 4: Run Pint**

Run: `vendor/bin/pint app/Livewire/SupplierPortal/ProductionScheduleGrid.php`
Expected: pass.

- [ ] **Step 5: Commit**

```bash
git add app/Livewire/SupplierPortal/ProductionScheduleGrid.php
git commit -m "refactor(operations): route ProductionSchedule submit through guarded transition

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: Route ProductionActualsGrid auto-complete through the guarded path

**Files:**
- Modify: `app/Livewire/Admin/ProductionActualsGrid.php`
- Regression net: `tests/Feature/Livewire/Admin/ProductionActualsGridTest.php` (existing)

- [ ] **Step 1: Baseline — run the existing actuals test**

Run: `php artisan test --filter=ProductionActualsGridTest`
Expected: PASS. Record green before changing code.

- [ ] **Step 2: Refactor `checkAutoComplete()`**

In `app/Livewire/Admin/ProductionActualsGrid.php`, in `checkAutoComplete()`, the surrounding `if` already guarantees `status === Approved` and the totals are met. Replace `$this->schedule->update(['status' => ProductionScheduleStatus::Completed]);` with:
```php
            $this->schedule->transitionTo(ProductionScheduleStatus::Completed->value);
```
Keep the `$this->schedule->refresh();` and the success `Notification` that follow. No aux fields here, so plain `transitionTo()` (no `sideEffects`) is correct. No new import needed beyond what the file already has (the `transitionTo` is a model method).

- [ ] **Step 3: Run the existing actuals test — must still pass**

Run: `php artisan test --filter=ProductionActualsGridTest`
Expected: PASS (full quantity → Completed; partial → stays Approved; Draft case unaffected).

- [ ] **Step 4: Run the full suite**

Run: `composer test`
Expected: all green, 0 failures (prior 558 passed + the 3 new ProductionScheduleStateMachineTest cases). Investigate any regression before committing.

- [ ] **Step 5: Run Pint**

Run: `vendor/bin/pint app/Livewire/Admin/ProductionActualsGrid.php`
Expected: pass.

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Admin/ProductionActualsGrid.php
git commit -m "refactor(operations): route ProductionSchedule auto-complete through guarded transition

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Self-Review

**Spec coverage (Layer 2 — ProductionSchedule pilot):**
- HasStateMachine + allowedTransitions → Task 1. ✓
- Route the 4 raw `update(['status'...])` sites through `execute()`/`transitionTo()`, preserving aux fields → Tasks 2–4. ✓
- Enum already implements Filament interfaces → no change needed (spec note obsolete; documented in Context). ✓
- No blockers for ProductionSchedule → inherits default `[]` (no `TransitionBlockedException`). ✓
- Audit logging via `StateTransition` → asserted in Task 1. ✓
- Existing Livewire tests preserve UI behavior → baseline+after gates in Tasks 2–4. ✓

**Placeholder scan:** none — all steps contain concrete code and exact commands. The one executor note (verify `state_transitions` table name) is a verification instruction, not a placeholder.

**Type consistency:** `allowedTransitions(): array` matches the trait's abstract signature. `transitionTo(string)` and `execute($model, string, sideEffects: callable)` match `TransitionStatusAction`/`HasStateMachine`. `ProductionScheduleStatus::X->value` (string) used consistently for transition targets. The `sideEffects` closure signature `function (ProductionSchedule $schedule): void` matches how `execute()` invokes `$sideEffects($model)`.

**Risk:** The aux-field writes move into the `sideEffects` closure (atomic, inside the transaction). Keeping the existing `!== PendingApproval` guards on approve/reject preserves the silent no-op behavior the existing tests assert (avoids an `\InvalidArgumentException` on out-of-state calls). The existing Livewire tests are the regression net for all three components.

## Out of scope (later sub-plans / follow-ups)
SupplierAudit and ProjectDevelopment (Project/MilestoneTask) state machines; routing `ExecuteShipmentPlanAction` and `EditPurchaseOrder::beforeSave()` through the central path (the two gaps found in Sub-plan A's final review); OperationsPipeline + Kanban + auto-advance (Sub-plan C).
