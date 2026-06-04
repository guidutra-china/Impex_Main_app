# Operations Pipeline Foundation (Phase 1) — Design

**Date:** 2026-06-04
**Status:** Approved design — pending implementation plans
**Branch target:** `fix/operations-phase1` (branched from `fix/operations-phase0-guard-bypass`)

## Context

The Operations workflow (Inquiry → Quotation → SupplierQuotation → ProformaInvoice →
PurchaseOrder → ProductionSchedule → ShipmentPlan → Shipment → Financial) was analyzed in a
prior strategy effort that mapped ~115 gaps across 11 domains and defined an 8-layer target
architecture. **Phase 0** (already implemented on `fix/operations-phase0-guard-bypass`) patched the
critical guard-bypass and authorization holes by adding a per-UI `blockers` hook and server-side
authz guards.

**Phase 1 (this spec)** delivers the *foundation* layers so that all later work builds on a single
guarded path rather than per-UI-surface enforcement. Scope was deliberately narrowed during
brainstorming:

- **Layer 3 — Single guarded transition path:** full.
- **Layer 2 — Universal state machine:** **`ProductionSchedule` only** (pilot). SupplierAudit and the
  ProjectDevelopment models (Project, MilestoneTask) are deferred — ProjectDevelopment is still
  untracked / under active development and its approval flow is mid-construction, so freezing a state
  machine over it now would cause rework.
- **Layer 1 — Declarative pipeline:** full — declare the pipeline once, drive the Kanban from it, plus
  one lifecycle auto-advance (PI `CONFIRMED` ⇒ Inquiry `WON`).

### The problem being solved

Status changes and business-rule blockers are enforced **per UI surface**. `TransitionStatusAction::execute()`
— the single domain entry point — only validates the state-machine (`canTransitionTo()`); it does **not**
enforce business blockers. So any caller that bypasses the UI (observer auto-transition to SHIPPED, a
future API, a Livewire component, a batch job) skips the guards. Phase 0 mitigated this for table vs.
header paths but left the duplication in place. Phase 1 moves enforcement **down** into the central
action so every caller is protected by construction.

## Chosen approach: central action enforcement (Approach A)

- Business blockers are declared **on the model** via a `HasStateMachine` method; lifecycle auto-advances
  are declared in a central `OperationsPipeline`.
- `TransitionStatusAction::execute()` enforces blockers (pre-transition, hard-block) and runs
  auto-advances (post-transition, best-effort) — both inside the existing flow.
- All callers (table, header, observer, Livewire, future API) get the same behavior for free.

Rejected alternatives: **Observers** (cannot cleanly abort a transition; blockers would fire after save —
weak for hard-blocks); **UI-layer-only helpers** (does not close the core gap — non-UI callers still
bypass).

---

## Layer 3 — Single guarded transition path

### New trait contract

```php
// app/Domain/Infrastructure/Traits/HasStateMachine.php
// Default: no blockers. Models with business rules override.
public function getTransitionBlockers(string $toStatus): array
{
    return [];
}
```

Models that already have blocker-producing methods point to them (no logic re-implementation):

```php
// ProformaInvoice
public function getTransitionBlockers(string $toStatus): array
{
    return $toStatus === ProformaInvoiceStatus::FINALIZED->value
        ? $this->getFinalizationBlockers()
        : [];
}

// PurchaseOrder — getBlockingPaymentLabels() already returns [] for non-blocking targets
public function getTransitionBlockers(string $toStatus): array
{
    return $this->getBlockingPaymentLabels($toStatus);
}

// ShipmentPlan
public function getTransitionBlockers(string $toStatus): array
{
    return $toStatus === ShipmentPlanStatus::SHIPPED->value
        ? $this->blocking_payment_labels   // existing accessor
        : [];
}
```

### New exception

```php
// app/Domain/Infrastructure/Exceptions/TransitionBlockedException.php
class TransitionBlockedException extends \RuntimeException
{
    /** @param string[] $blockers */
    public function __construct(public readonly array $blockers)
    {
        parent::__construct(implode("\n", $blockers));
    }
}
```

### Central enforcement

In `TransitionStatusAction::execute()`, after the `canTransitionTo()` check and **before** the DB
transaction:

```php
$blockers = $model->getTransitionBlockers($toStatusValue);
if (! empty($blockers)) {
    throw new TransitionBlockedException($blockers);
}
```

### Effect on Phase 0

The per-UI `blockers` hook added to `StatusTransitionActions` and the inline blocker checks in the PO
header action become **redundant and are removed**. The existing `try/catch` in the Filament actions
catches `TransitionBlockedException` and renders `$e->getMessage()` in the notification body — UI behavior
is unchanged for the user; the rule simply moves one layer down. `ShipmentPlan`, which today blocks only
via `ExecuteShipmentPlanAction`, gains the same block on the status path — a single consistent guard.

### Design notes

- Blockers are **hard-block** (throw). Soft-warning / override is deferred (Layer 4); the design leaves
  room for a future `force: true` + permission parameter on `execute()`.
- `getMessage()` joins messages with `\n`; Filament actions already render this in `->body()`.

---

## Layer 2 — ProductionSchedule state machine (pilot)

Today `ProductionSchedule.status` is mutated in **5 raw `->update()` sites** inside Livewire components,
with no validation.

### Declare transitions

```php
// ProductionSchedule.php — adopts HasStateMachine
use HasStateMachine;

public static function allowedTransitions(): array
{
    return [
        ProductionScheduleStatus::Draft->value           => [ProductionScheduleStatus::PendingApproval->value],
        ProductionScheduleStatus::Rejected->value        => [ProductionScheduleStatus::PendingApproval->value],
        ProductionScheduleStatus::PendingApproval->value => [
            ProductionScheduleStatus::Approved->value,
            ProductionScheduleStatus::Rejected->value,
        ],
        ProductionScheduleStatus::Approved->value        => [ProductionScheduleStatus::Completed->value],
        ProductionScheduleStatus::Completed->value       => [],
    ];
}
```

### Route raw updates through the guarded path

| Site | Transition | Becomes |
|---|---|---|
| `app/Livewire/SupplierPortal/ProductionScheduleGrid.php:164` (supplier submit) | Draft/Rejected → PendingApproval | `execute(..., PendingApproval)` |
| `app/Livewire/Portal/ScheduleApprovalWidget.php:27` (client approve) | PendingApproval → Approved | `execute(..., Approved)` |
| `app/Livewire/Portal/ScheduleApprovalWidget.php:46` (client reject) | PendingApproval → Rejected | `execute(..., Rejected)` |
| `app/Livewire/Admin/ProductionActualsGrid.php:73` (auto on actual qty reached) | Approved → Completed | `execute(..., Completed)` — idempotent (`if canTransitionTo`) |

(`CreateProductionSchedule.php:17` sets the initial `Draft` on creation — left as-is; it is not a
transition.)

### Design notes

- The enum business methods `canBeEditedBySupplier()` / `canRequestEdit()` stay intact — orthogonal to
  the state machine.
- `ProductionScheduleStatus` does **not** currently implement `HasLabel`/`HasColor`/`HasIcon` (every other
  status enum does, and `StatusTransitionActions` calls `getLabel()`/`getColor()`). Align it to the
  Filament interfaces as a targeted improvement to the code we are touching.
- `ProductionSchedule` has **no** business blockers this phase → `getTransitionBlockers()` inherits the
  default `[]`. It gains the guarded path + `StateTransition` audit log for free.
- The auto-`Completed` keeps firing from the same trigger (actual quantity reached), now validated and
  logged.

---

## Layer 1 — Declarative pipeline + Kanban + auto-advance

### Central stage declaration

```php
// app/Domain/Operations/OperationsPipeline.php
class OperationsPipeline
{
    /** End-to-end stages, in order. Single source of truth. */
    public static function stages(): array
    {
        return [
            new PipelineStage('inquiry',      Inquiry::class,        [InquiryStatus::RECEIVED, InquiryStatus::QUOTING]),
            new PipelineStage('quoting',      Quotation::class,      [QuotationStatus::QUOTED]),
            new PipelineStage('pi_issued',    ProformaInvoice::class,[/* DRAFT..REOPENED */]),
            new PipelineStage('in_production',PurchaseOrder::class,  [/* CONFIRMED..AWAITING_SHIPMENT */]),
            new PipelineStage('shipping',     Shipment::class,       [/* BOOKED..IN_TRANSIT */]),
            new PipelineStage('delivered',    Shipment::class,       [ShipmentStatus::ARRIVED]),
        ];
    }
}
```

`PipelineStage` is a simple value object: `key`, `modelClass`, `statuses[]`, optional `eagerLoad[]`.
The exact status sets mirror the current `OrderPipelineKanban` columns (preserve existing behavior).

### Kanban derives from the pipeline

`app/Filament/Pages/OrderPipelineKanban.php` currently has 6 `buildXColumn()` methods with hardcoded
`whereIn()` filters. It iterates `OperationsPipeline::stages()` instead, building each column from the
stage's `modelClass` + `statuses`. Per-stage eager-loads move onto `PipelineStage`. No visual change;
removes the duplication flagged in Phase 0 code review.

### Declarative auto-advance (PI CONFIRMED ⇒ Inquiry WON)

```php
public static function autoAdvances(): array
{
    return [
        new AutoAdvance(
            ProformaInvoice::class,
            ProformaInvoiceStatus::CONFIRMED->value,
            fn (ProformaInvoice $pi) => $pi->inquiry,   // target resolver (inquiry_id is non-nullable FK)
            InquiryStatus::WON->value,
        ),
    ];
}
```

`TransitionStatusAction::execute()`, **after** committing the transition (inside the same transaction),
looks up auto-advances for the `(modelClass, toStatus)` that just occurred and runs each as a
**best-effort** idempotent transition:

```php
foreach (OperationsPipeline::autoAdvancesFor($model, $toStatusValue) as $advance) {
    try {
        $target = ($advance->resolveTarget)($model);
        if ($target && $target->canTransitionTo($advance->toStatus)) {
            $this->execute($target, $advance->toStatus, notes: 'Auto-avanço: PI confirmada');
        }
    } catch (\Throwable $e) {
        report($e); // best-effort: never break the originating transition
    }
}
```

### Design notes

- Auto-advance lives in the **central path**, not in duplicated `sideEffects`. Confirming a PI via table,
  header, or any future path advances the Inquiry.
- **Best-effort:** wrapped in `try/catch`; if the Inquiry advance fails it is reported and skipped — the
  PI confirmation never breaks.
- **Idempotent / safe:** if the Inquiry is already WON/LOST/CANCELLED (`canTransitionTo` false), it is
  silently skipped — same pattern as `SyncFulfillmentStatusAction`.
- **Recursion terminates naturally:** the auto-advance calls `execute()` for the Inquiry, which checks
  `autoAdvancesFor(Inquiry, WON)` — none registered, so it stops. No loop.
- The existing CONFIRMED `sideEffects` (`SyncClientProductPricesAction`) is unchanged — auto-advance is
  additive.

---

## Testing strategy (TDD — test fails first)

| Area | Tests |
|---|---|
| Layer 3 | PI CONFIRMED with pending PSI → `execute(..., FINALIZED)` throws `TransitionBlockedException`, status unchanged; PO with blocking installment → `execute` throws on blocking targets; non-blocked transition passes. Phase 0 tests (FinalizeGuard, PaymentBlockGuard) stay green, now exercising the central path. |
| Layer 2 | Each of the 4 valid transitions passes and creates a `StateTransition`; an invalid transition (e.g. Draft→Completed) throws; auto-Completed is idempotent (no duplicate). |
| Layer 1 | `OperationsPipeline::stages()` covers all 6 stages; Kanban renders columns from the pipeline; confirming a PI (header **and** table) advances Inquiry QUOTED→WON; already-WON/LOST Inquiry is skipped without error; a failing advance does not break the PI. |

`vendor/bin/pint` + `composer test` before every commit.

## Implementation sequencing (sub-plans)

Each sub-plan is an atomic, independently testable commit, in dependency order. Each becomes an
`XX-PLAN.md` approved before coding.

1. **Sub-plan A — Central guarded path (Layer 3):** `getTransitionBlockers()` on trait + 3 models,
   `TransitionBlockedException`, enforcement in `execute()`, **removal of the now-redundant Phase 0
   `blockers` hooks**. UI behavior unchanged.
2. **Sub-plan B — ProductionSchedule state machine (Layer 2):** enum aligned to Filament interfaces +
   `allowedTransitions` + trait + route the 5 raw `update()` sites through `execute()`.
3. **Sub-plan C — OperationsPipeline + Kanban + auto-advance (Layer 1):** `PipelineStage` /
   `OperationsPipeline`, Kanban derives from the pipeline, `AutoAdvance` PI⇒Inquiry in `execute()`
   (best-effort).

## Rollout / branch

- Create `fix/operations-phase1` from `fix/operations-phase0-guard-bypass`. Pending uncommitted changes
  currently on `main` are resolved with the user at branch time (not dragged or lost). Phase 0 and Phase 1
  ship together later.
- **No migrations** — all changes are in code (enums, traits, actions). `StateTransition` log already
  exists; permissions already exist.
- Primary risk: `execute()` is the heart of 9+ models. Mitigation: the full suite (553 tests) runs after
  every sub-plan; any transition regression surfaces immediately.

## Out of scope (future phases)

SupplierAudit + Project/MilestoneTask in the state machine; cross-feature prerequisite locks (Layer 4);
item mutation locks (Layer 6); cascade symmetry (Layer 7); audit-result qualification gate (Layer 8); and
the soft-warning / override mechanism.
