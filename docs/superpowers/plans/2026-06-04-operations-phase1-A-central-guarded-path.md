# Operations Phase 1 — Sub-plan A: Central Guarded Transition Path Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move business-rule blocker enforcement out of the per-UI surfaces and into the single domain transition path (`TransitionStatusAction::execute()`), so every caller — table, header, observer, Livewire, future API — is guarded by construction.

**Architecture:** Models declare their blockers via a new `HasStateMachine::getTransitionBlockers($toStatus)` method (default `[]`). `execute()` calls it after the state-machine check and throws `TransitionBlockedException` when non-empty. The now-redundant Phase 0 `blockers` UI hooks are removed; the existing `try/catch` in the Filament actions renders the exception message, so UI behavior is unchanged.

**Tech Stack:** Laravel 12, PHP 8.3, Filament 4, PHPUnit, Pint.

**Branch:** `fix/operations-phase1` (already created from `fix/operations-phase0-guard-bypass`).

**Spec:** `docs/superpowers/specs/2026-06-04-operations-pipeline-foundation-design.md` (Layer 3).

---

## File Structure

| File | Responsibility | Action |
|---|---|---|
| `app/Domain/Infrastructure/Exceptions/TransitionBlockedException.php` | Typed exception carrying blocker messages | Create |
| `app/Domain/Infrastructure/Traits/HasStateMachine.php` | Default `getTransitionBlockers()` returning `[]` | Modify |
| `app/Domain/Infrastructure/Actions/TransitionStatusAction.php` | Enforce blockers before the DB transaction | Modify |
| `app/Domain/ProformaInvoices/Models/ProformaInvoice.php` | Override: finalize blockers | Modify |
| `app/Domain/PurchaseOrders/Models/PurchaseOrder.php` | Override: payment blockers | Modify |
| `app/Domain/Planning/Models/ShipmentPlan.php` | Override: shipment payment blockers | Modify |
| `app/Filament/Actions/StatusTransitionActions.php` | Remove redundant `blockers` hook | Modify |
| `app/Filament/Resources/ProformaInvoices/Tables/ProformaInvoicesTable.php` | Remove `blockers` override line | Modify |
| `app/Filament/Resources/PurchaseOrders/Tables/PurchaseOrdersTable.php` | Remove `blockers` overrides | Modify |
| `app/Filament/Resources/PurchaseOrders/Concerns/PurchaseOrderHeaderActions.php` | Remove inline blocker check | Modify |
| `tests/Feature/Operations/CentralTransitionGuardTest.php` | Action-level enforcement tests | Create (Test) |

---

## Task 1: Central enforcement + ProformaInvoice override (vertical slice)

**Files:**
- Create: `app/Domain/Infrastructure/Exceptions/TransitionBlockedException.php`
- Modify: `app/Domain/Infrastructure/Traits/HasStateMachine.php` (after `getAllowedNextStatuses()`, ~line 61)
- Modify: `app/Domain/Infrastructure/Actions/TransitionStatusAction.php` (inside `execute()`, after the `canTransitionTo()` throw, before `return DB::transaction(...)`)
- Modify: `app/Domain/ProformaInvoices/Models/ProformaInvoice.php` (after `getFinalizationBlockers()`, ~line 301)
- Test: `tests/Feature/Operations/CentralTransitionGuardTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Operations/CentralTransitionGuardTest.php`:

```php
<?php

namespace Tests\Feature\Operations;

use App\Domain\Infrastructure\Actions\TransitionStatusAction;
use App\Domain\Infrastructure\Exceptions\TransitionBlockedException;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CentralTransitionGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_execute_throws_when_proforma_invoice_finalization_is_blocked(): void
    {
        $pi = ProformaInvoice::factory()->create([
            'status' => ProformaInvoiceStatus::CONFIRMED->value,
        ]);
        ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description' => 'Widget',
            'quantity' => 10,
            'unit_price' => 100,
            'sort_order' => 0,
        ]);

        // Sanity: a genuine blocker exists (item not fully shipped).
        $this->assertNotEmpty($pi->getFinalizationBlockers());

        try {
            app(TransitionStatusAction::class)->execute($pi, ProformaInvoiceStatus::FINALIZED);
            $this->fail('Expected TransitionBlockedException was not thrown.');
        } catch (TransitionBlockedException $e) {
            $this->assertNotEmpty($e->blockers);
        }

        $this->assertSame(
            ProformaInvoiceStatus::CONFIRMED->value,
            $pi->fresh()->status->value,
            'PI status must be unchanged when blocked.'
        );
    }

    public function test_execute_succeeds_when_proforma_invoice_has_no_blockers(): void
    {
        $pi = ProformaInvoice::factory()->create([
            'status' => ProformaInvoiceStatus::CONFIRMED->value,
        ]);

        $this->assertEmpty($pi->getFinalizationBlockers());

        app(TransitionStatusAction::class)->execute($pi, ProformaInvoiceStatus::FINALIZED);

        $this->assertSame(
            ProformaInvoiceStatus::FINALIZED->value,
            $pi->fresh()->status->value,
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CentralTransitionGuardTest`
Expected: FAIL — `TransitionBlockedException` class does not exist (or the blocked transition is allowed through and status becomes `finalized`).

- [ ] **Step 3: Create the exception**

Create `app/Domain/Infrastructure/Exceptions/TransitionBlockedException.php`:

```php
<?php

namespace App\Domain\Infrastructure\Exceptions;

class TransitionBlockedException extends \RuntimeException
{
    /**
     * @param  string[]  $blockers  Human-readable business-rule blocker messages.
     */
    public function __construct(public readonly array $blockers)
    {
        parent::__construct(implode("\n", $blockers));
    }
}
```

- [ ] **Step 4: Add the default `getTransitionBlockers()` to the trait**

In `app/Domain/Infrastructure/Traits/HasStateMachine.php`, after `getAllowedNextStatuses()` (line 61), add:

```php
    /**
     * Business-rule blockers for a transition to $toStatus.
     * Default: none. Models with prerequisites override this and return
     * an array of human-readable messages; a non-empty array hard-blocks
     * the transition in TransitionStatusAction::execute().
     *
     * @return string[]
     */
    public function getTransitionBlockers(string $toStatus): array
    {
        return [];
    }
```

- [ ] **Step 5: Enforce blockers in `execute()`**

In `app/Domain/Infrastructure/Actions/TransitionStatusAction.php`, add the import at the top:

```php
use App\Domain\Infrastructure\Exceptions\TransitionBlockedException;
```

Then inside `execute()`, immediately after the `if (! $model->canTransitionTo($toStatusValue)) { throw new \InvalidArgumentException(...); }` block and **before** `return DB::transaction(...)`, insert:

```php
        $blockers = $model->getTransitionBlockers($toStatusValue);
        if (! empty($blockers)) {
            throw new TransitionBlockedException($blockers);
        }
```

- [ ] **Step 6: Add the ProformaInvoice override**

In `app/Domain/ProformaInvoices/Models/ProformaInvoice.php`, after `getFinalizationBlockers()` ends (~line 301, before `canFinalize()`), add:

```php
    public function getTransitionBlockers(string $toStatus): array
    {
        return $toStatus === ProformaInvoiceStatus::FINALIZED->value
            ? $this->getFinalizationBlockers()
            : [];
    }
```

(`ProformaInvoiceStatus` is already imported in this model — it is used by `getFinalizationBlockers()` / status casts. Verify the `use` exists; add it if missing.)

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --filter=CentralTransitionGuardTest`
Expected: PASS (both tests).

- [ ] **Step 8: Run Pint**

Run: `vendor/bin/pint app/Domain/Infrastructure app/Domain/ProformaInvoices/Models/ProformaInvoice.php tests/Feature/Operations`
Expected: pass.

- [ ] **Step 9: Commit**

```bash
git add app/Domain/Infrastructure/Exceptions/TransitionBlockedException.php \
        app/Domain/Infrastructure/Traits/HasStateMachine.php \
        app/Domain/Infrastructure/Actions/TransitionStatusAction.php \
        app/Domain/ProformaInvoices/Models/ProformaInvoice.php \
        tests/Feature/Operations/CentralTransitionGuardTest.php
git commit -m "feat(operations): enforce transition blockers centrally in TransitionStatusAction

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: PurchaseOrder blocker override

**Files:**
- Modify: `app/Domain/PurchaseOrders/Models/PurchaseOrder.php` (in the `// --- HasStateMachine ---` region, ~line 86+)
- Test: `tests/Feature/Operations/CentralTransitionGuardTest.php` (add method)

`PurchaseOrder` already uses `HasPaymentSchedule` (which provides `getBlockingPaymentLabels(string $toStatus): array`, returning `[]` for non-blocking targets) and `HasStateMachine`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Operations/CentralTransitionGuardTest.php` (add the imports `use App\Domain\Financial\Models\PaymentScheduleItem;`, `use App\Domain\Financial\Enums\CalculationBase;`, `use App\Domain\Financial\Enums\PaymentScheduleStatus;`, `use App\Domain\PurchaseOrders\Enums\PurchaseOrderStatus;`, `use App\Domain\PurchaseOrders\Models\PurchaseOrder;` at the top):

```php
    public function test_execute_throws_when_purchase_order_has_blocking_payment(): void
    {
        $po = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::CONFIRMED->value,
        ]);

        PaymentScheduleItem::factory()->create([
            'payable_type' => $po->getMorphClass(),
            'payable_id' => $po->getKey(),
            'is_blocking' => true,
            'is_credit' => false,
            'status' => PaymentScheduleStatus::DUE->value,
            'due_condition' => CalculationBase::BEFORE_PRODUCTION->value,
        ]);

        // Sanity: the PO reports the blocker for the in_production target.
        $this->assertNotEmpty($po->getBlockingPaymentLabels(PurchaseOrderStatus::IN_PRODUCTION->value));

        try {
            app(TransitionStatusAction::class)->execute($po, PurchaseOrderStatus::IN_PRODUCTION);
            $this->fail('Expected TransitionBlockedException was not thrown.');
        } catch (TransitionBlockedException $e) {
            $this->assertNotEmpty($e->blockers);
        }

        $this->assertSame(
            PurchaseOrderStatus::CONFIRMED->value,
            $po->fresh()->status->value,
        );
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=test_execute_throws_when_purchase_order_has_blocking_payment`
Expected: FAIL — transition is allowed through (PO has no `getTransitionBlockers` override yet), status becomes `in_production`.

- [ ] **Step 3: Add the PurchaseOrder override**

In `app/Domain/PurchaseOrders/Models/PurchaseOrder.php`, inside the `// --- HasStateMachine ---` region (after `allowedTransitions()`), add:

```php
    public function getTransitionBlockers(string $toStatus): array
    {
        return $this->getBlockingPaymentLabels($toStatus);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=test_execute_throws_when_purchase_order_has_blocking_payment`
Expected: PASS.

- [ ] **Step 5: Run Pint**

Run: `vendor/bin/pint app/Domain/PurchaseOrders/Models/PurchaseOrder.php tests/Feature/Operations`
Expected: pass.

- [ ] **Step 6: Commit**

```bash
git add app/Domain/PurchaseOrders/Models/PurchaseOrder.php tests/Feature/Operations/CentralTransitionGuardTest.php
git commit -m "feat(operations): PurchaseOrder declares payment blockers for central guard

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: ShipmentPlan blocker override

**Files:**
- Modify: `app/Domain/Planning/Models/ShipmentPlan.php` (in the `// --- HasStateMachine ---` region, ~line 69+)
- Test: `tests/Feature/Operations/CentralTransitionGuardTest.php` (add method)

`ShipmentPlan` already uses `HasPaymentSchedule` + `HasStateMachine`, and exposes `getBlockingPaymentLabelsAttribute()` (accessor `$model->blocking_payment_labels`) and `hasBlockingPayments()`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Operations/CentralTransitionGuardTest.php` (add imports `use App\Domain\Planning\Enums\ShipmentPlanStatus;`, `use App\Domain\Planning\Models\ShipmentPlan;`):

```php
    public function test_execute_throws_when_shipment_plan_has_blocking_payment(): void
    {
        $plan = ShipmentPlan::factory()->create([
            'status' => ShipmentPlanStatus::CONFIRMED->value,
        ]);

        PaymentScheduleItem::factory()->create([
            'payable_type' => $plan->getMorphClass(),
            'payable_id' => $plan->getKey(),
            'is_blocking' => true,
            'is_credit' => false,
            'status' => PaymentScheduleStatus::DUE->value,
            'due_condition' => CalculationBase::BEFORE_SHIPMENT->value,
        ]);

        $this->assertTrue($plan->hasBlockingPayments());

        try {
            app(TransitionStatusAction::class)->execute($plan, ShipmentPlanStatus::SHIPPED);
            $this->fail('Expected TransitionBlockedException was not thrown.');
        } catch (TransitionBlockedException $e) {
            $this->assertNotEmpty($e->blockers);
        }

        $this->assertSame(
            ShipmentPlanStatus::CONFIRMED->value,
            $plan->fresh()->status->value,
        );
    }
```

> **Note for executor:** If `ShipmentPlan` PSIs require a different polymorphic wiring than shown (e.g., a `source_type` column or a non-null FK), inspect `ShipmentPlan::hasBlockingPayments()` / the `PaymentScheduleItem` factory and adjust the factory attributes so `hasBlockingPayments()` returns `true`. The assertion `$this->assertTrue($plan->hasBlockingPayments())` is the contract — make the setup satisfy it before implementing.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=test_execute_throws_when_shipment_plan_has_blocking_payment`
Expected: FAIL — transition to `shipped` is allowed through; status becomes `shipped`.

- [ ] **Step 3: Add the ShipmentPlan override**

In `app/Domain/Planning/Models/ShipmentPlan.php`, inside the `// --- HasStateMachine ---` region (after `allowedTransitions()`), add (confirm `ShipmentPlanStatus` is imported; it is used by the status cast):

```php
    public function getTransitionBlockers(string $toStatus): array
    {
        return $toStatus === ShipmentPlanStatus::SHIPPED->value
            ? $this->blocking_payment_labels
            : [];
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=test_execute_throws_when_shipment_plan_has_blocking_payment`
Expected: PASS.

- [ ] **Step 5: Run Pint**

Run: `vendor/bin/pint app/Domain/Planning/Models/ShipmentPlan.php tests/Feature/Operations`
Expected: pass.

- [ ] **Step 6: Commit**

```bash
git add app/Domain/Planning/Models/ShipmentPlan.php tests/Feature/Operations/CentralTransitionGuardTest.php
git commit -m "feat(operations): ShipmentPlan declares shipment payment blockers for central guard

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: Remove the now-redundant Phase 0 UI blocker hooks

The central path now enforces blockers, so the per-UI `blockers` hook and its wiring are dead weight. Removing them resolves the duplication and keeps a single source of truth. The Filament actions already wrap `execute()` in `try/catch` and render `$e->getMessage()`, so catching `TransitionBlockedException` keeps the UX identical.

**Files:**
- Modify: `app/Filament/Actions/StatusTransitionActions.php` (remove the `blockers` override key + its pre-`execute()` check)
- Modify: `app/Filament/Resources/ProformaInvoices/Tables/ProformaInvoicesTable.php` (remove the `'blockers' => ...` line in the `finalized` override)
- Modify: `app/Filament/Resources/PurchaseOrders/Tables/PurchaseOrdersTable.php` (remove the `'blockers' => ...` line in `confirmed`, and the entire `in_production` and `shipped` override entries which become empty)
- Modify: `app/Filament/Resources/PurchaseOrders/Concerns/PurchaseOrderHeaderActions.php` (remove the inline `getBlockingPaymentLabels()` check block)

- [ ] **Step 1: Confirm the Phase 0 regression tests exist and pass first (baseline)**

Run: `php artisan test --filter=FinalizeGuardTest && php artisan test --filter=PaymentBlockGuardTest`
Expected: PASS (these exercise the table/header bypass scenarios). They are the safety net for this refactor — they must stay green after the removals.

- [ ] **Step 2: Remove the `blockers` hook from `StatusTransitionActions`**

In `app/Filament/Actions/StatusTransitionActions.php`:

1. In the PHPDoc shape (line 18), drop `, blockers?: callable` and the trailing sentence about `blockers`.
2. Delete the line `$blockers = $override['blockers'] ?? null;` (line 36).
3. In the `->action(...)` closure, remove `$blockers` from the `use (...)` list and delete the whole guard block:

```php
                    // Business-rule guard: parity with header actions so the table path
                    // cannot bypass blockers (e.g. PI finalization, PO payment blocks).
                    if ($blockers !== null) {
                        $messages = $blockers($record);

                        if (! empty($messages)) {
                            Notification::make()
                                ->title(__('messages.status_transition_failed'))
                                ->body(implode("\n", $messages))
                                ->danger()
                                ->send();

                            return;
                        }
                    }
```

The `try/catch` around `execute()` stays — it now catches `TransitionBlockedException` and renders its message. (No code change needed there: `$e->getMessage()` already returns the joined blocker list.)

- [ ] **Step 3: Remove the PI table `blockers` override line**

In `app/Filament/Resources/ProformaInvoices/Tables/ProformaInvoicesTable.php`, inside the `'finalized' => [ ... ]` override, delete the line:

```php
                            'blockers' => fn ($record) => $record->getFinalizationBlockers(),
```

Keep the rest of the `finalized` override (`icon`, `color`, `requiresConfirmation`).

- [ ] **Step 4: Remove the PO table `blockers` overrides**

In `app/Filament/Resources/PurchaseOrders/Tables/PurchaseOrdersTable.php`:

- In the `'confirmed' => [ ... ]` override, delete the line:
  ```php
                            'blockers' => fn ($record) => $record->getBlockingPaymentLabels(PurchaseOrderStatus::CONFIRMED->value),
  ```
  Keep `icon`, `color`, `requiresConfirmation`, `sideEffects`.
- Delete the entire `'in_production' => [ ... ]` and `'shipped' => [ ... ]` override entries (they contained only the `blockers` key and are now empty).

- [ ] **Step 5: Remove the PO header inline blocker check**

In `app/Filament/Resources/PurchaseOrders/Concerns/PurchaseOrderHeaderActions.php`, inside the `transitionStatusAction()` action closure, delete the block:

```php
                    $blockerLabels = $this->record->getBlockingPaymentLabels($newStatus->value);

                    if (count($blockerLabels) > 0) {
                        Notification::make()
                            ->title(__('messages.status_change_blocked'))
                            ->body(__('messages.payments_must_be_resolved', ['labels' => implode(', ', $blockerLabels)]))
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }
```

The surrounding `try/catch` (which renders `$e->getMessage()`) stays and now surfaces `TransitionBlockedException`. If the `__('messages.status_change_blocked')` / `payments_must_be_resolved` keys become unused after this, leave them in the lang files (harmless; may be reused).

- [ ] **Step 6: Run the Phase 0 regression tests — they must still pass**

Run: `php artisan test --filter=FinalizeGuardTest && php artisan test --filter=PaymentBlockGuardTest`
Expected: PASS — the blocks now come from the central path, proving UI parity.

- [ ] **Step 7: Run the full suite**

Run: `composer test`
Expected: all green (previously 553 passed + the new `CentralTransitionGuardTest` cases; 0 failures). Investigate any regression before continuing.

- [ ] **Step 8: Run Pint**

Run: `vendor/bin/pint app/Filament`
Expected: pass.

- [ ] **Step 9: Commit**

```bash
git add app/Filament/Actions/StatusTransitionActions.php \
        app/Filament/Resources/ProformaInvoices/Tables/ProformaInvoicesTable.php \
        app/Filament/Resources/PurchaseOrders/Tables/PurchaseOrdersTable.php \
        app/Filament/Resources/PurchaseOrders/Concerns/PurchaseOrderHeaderActions.php
git commit -m "refactor(operations): drop redundant per-UI blocker hooks now enforced centrally

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Self-Review

**Spec coverage (Layer 3):**
- `getTransitionBlockers()` on trait + 3 models → Tasks 1–3. ✓
- `TransitionBlockedException` + enforcement in `execute()` → Task 1. ✓
- Removal of redundant Phase 0 hooks → Task 4. ✓
- Phase 0 tests stay green exercising central path → Task 4 Steps 1, 6, 7. ✓

**Out of scope for this plan (later sub-plans):** ProductionSchedule state machine (Sub-plan B), OperationsPipeline + Kanban + auto-advance (Sub-plan C).

**Type consistency:** `getTransitionBlockers(string $toStatus): array` is identical in the trait default and all three overrides. `TransitionBlockedException::$blockers` (public readonly array) is asserted in tests via `$e->blockers`. `getBlockingPaymentLabels(string): array` (PO) and `blocking_payment_labels` accessor (ShipmentPlan) match their model definitions.

**Risk:** `execute()` is shared by 9+ models; the new check is a no-op for models without an override (default `[]`). The full suite in Task 4 Step 7 is the regression gate.
