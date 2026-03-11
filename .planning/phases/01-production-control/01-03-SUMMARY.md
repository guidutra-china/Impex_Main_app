---
phase: 01-production-control
plan: "03"
subsystem: payments
tags: [domain-action, payment-schedule, production-readiness, filament, supplier-portal, tdd]

# Dependency graph
requires:
  - phase: 01-01
    provides: ProductionScheduleEntry with actual_quantity field, EntriesRelationManager in Supplier Portal

provides:
  - UpdatePaymentScheduleFromProductionAction domain action (calculates overall PI readiness, sets due_date on AFTER_PRODUCTION payment items)
  - Idempotent payment schedule update (whereNull due_date guard prevents re-triggering)
  - Supplier Portal wiring — EditAction::after() on EntriesRelationManager triggers action on save
  - 13 new feature tests covering action behavior and portal wiring

affects:
  - 02-financial-reporting (payment items now auto-dated from production)
  - 03-shipment-control (AFTER_PRODUCTION items will have due_dates before shipment flow)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Domain Action pattern: single execute() method, DB::transaction, explicit return value"
    - "Filament EditAction::after() callback for side-effect triggering on save"
    - "Idempotency via whereNull filter — items already dated are never re-processed"
    - "Overall PI readiness (not per-item): sum(actual_quantity) / sum(pi_item.quantity) * 100"

key-files:
  created:
    - app/Domain/Planning/Actions/UpdatePaymentScheduleFromProductionAction.php
    - tests/Feature/PaymentScheduleProductionTest.php
    - tests/Feature/SupplierPortalPaymentWiringTest.php
  modified:
    - app/Filament/SupplierPortal/Resources/ProductionScheduleResource/RelationManagers/EntriesRelationManager.php

key-decisions:
  - "Readiness calculated at overall PI level (not per PI item) because PaymentScheduleItems are PI-scoped"
  - "Idempotency guard is whereNull('due_date') — items already dated are permanently excluded from updates"
  - "Action wired via EditAction::after() not a model observer — follows DDD Action pattern, avoids implicit side-effects"
  - "Wiring test uses structural assertion on EntriesRelationManager source to verify code in place"

patterns-established:
  - "PI-level payment triggers: always calculate readiness at PI aggregate level, not per line item"
  - "Action explicitness: financial side-effects triggered by explicit action calls, not model events"

requirements-completed:
  - PROD-04

# Metrics
duration: 3min
completed: 2026-03-11
---

# Phase 1 Plan 03: Payment Schedule Production Trigger Summary

**Domain action UpdatePaymentScheduleFromProductionAction sets due_date on AFTER_PRODUCTION payment items when overall PI production readiness crosses percentage threshold, wired to Supplier Portal save via EditAction::after()**

## Performance

- **Duration:** 3 min
- **Started:** 2026-03-11T08:17:18Z
- **Completed:** 2026-03-11T08:19:53Z
- **Tasks:** 2
- **Files modified:** 4

## Accomplishments

- Domain action calculates overall PI readiness (sum of all actual_quantity / sum of all PI item quantities * 100) and triggers due_date assignment on qualifying AFTER_PRODUCTION payment items
- Action is fully idempotent: `whereNull('due_date')` guard ensures items already dated are never re-processed
- Supplier Portal EntriesRelationManager wired with `EditAction::after()` — action fires automatically when supplier saves actual_quantity
- 13 feature tests: 9 for action behavior (threshold trigger, idempotency, status filtering, zero-qty guard, multi-entry aggregation) and 4 for portal wiring (integration + structural assertion)

## Task Commits

1. **Task 1: Create UpdatePaymentScheduleFromProductionAction with tests** - `45b63e5` (feat)
2. **Task 2: Wire action to Supplier Portal save flow with focused test** - `d979adb` (feat)

**Plan metadata:** (docs commit follows)

## Files Created/Modified

- `app/Domain/Planning/Actions/UpdatePaymentScheduleFromProductionAction.php` — Domain action: loads PI + entries, calculates readiness, updates PENDING AFTER_PRODUCTION items with null due_date
- `tests/Feature/PaymentScheduleProductionTest.php` — 9 feature tests covering all action behaviors
- `tests/Feature/SupplierPortalPaymentWiringTest.php` — 4 feature tests verifying portal save triggers action correctly
- `app/Filament/SupplierPortal/Resources/ProductionScheduleResource/RelationManagers/EntriesRelationManager.php` — Added import + `EditAction::after()` callback

## Decisions Made

- **Overall PI readiness**: Readiness is calculated as `sum(actual_quantity across all entries) / sum(PI item quantities)`. This is intentional because PaymentScheduleItems are scoped to the PI, not individual line items.
- **Idempotency design**: The `whereNull('due_date')` filter in the query is the idempotency guard — once a due_date is set, the item permanently leaves the update pool. No need for a separate "already processed" flag.
- **No model observer**: Action is called explicitly from `EditAction::after()` following the DDD Action pattern. Avoids implicit side-effects that are hard to trace.

## Deviations from Plan

None — plan executed exactly as written.

## Issues Encountered

None.

## Next Phase Readiness

- Phase 1 complete: production actuals -> payment schedule auto-dating is fully implemented
- AFTER_PRODUCTION payment items will now receive due_dates automatically when suppliers report production
- Full test suite is green (121 tests, 191 assertions)

---
*Phase: 01-production-control*
*Completed: 2026-03-11*
