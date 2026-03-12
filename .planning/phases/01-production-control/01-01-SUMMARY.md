---
phase: 01-production-control
plan: 01
subsystem: database, ui, testing
tags: [filament, spatie-permissions, multi-tenancy, production-schedule, sqlite, tdd]

# Dependency graph
requires: []
provides:
  - actual_quantity nullable integer column on production_schedule_entries via additive migration
  - ProductionScheduleEntry with actual_quantity in fillable and integer cast
  - ProductionSchedule::getTotalActualQuantityAttribute() summing actual across entries (null=0)
  - ProductionSchedule::getShipmentReadyQuantityByItem() returning Collection keyed by PI item ID
  - Supplier Portal ProductionScheduleResource with tenantOwnershipRelationshipName = supplierCompany
  - Portal EntriesRelationManager exposing only actual_quantity for edit (read-only: quantity, production_date)
  - Permissions: supplier-portal:view-production-schedules, supplier-portal:update-production-actuals
affects:
  - 01-02 (production comparison logic depends on actual_quantity column)
  - 01-03 (shipment-ready quantity uses getShipmentReadyQuantityByItem)
  - 01-04 (payment automation depends on actual vs planned comparison)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Supplier Portal resources follow PurchaseOrderResource pattern: tenantOwnershipRelationshipName = supplierCompany, canCreate/Edit/Delete = false, canAccess checks permission"
    - "Portal-specific RelationManagers are separate from admin RelationManagers — restricted field set for supplier editing"
    - "Feature tests use Permission::firstOrCreate() for test-safe permission setup (no seeder dependency)"
    - "null vs 0 distinction: null = not yet reported, 0 = reported zero production — always use !== null check"

key-files:
  created:
    - database/migrations/2026_03_11_100000_add_actual_quantity_to_production_schedule_entries.php
    - app/Filament/SupplierPortal/Resources/ProductionScheduleResource.php
    - app/Filament/SupplierPortal/Resources/ProductionScheduleResource/Pages/ListProductionSchedules.php
    - app/Filament/SupplierPortal/Resources/ProductionScheduleResource/Pages/ViewProductionSchedule.php
    - app/Filament/SupplierPortal/Resources/ProductionScheduleResource/RelationManagers/EntriesRelationManager.php
    - tests/Unit/ProductionScheduleActualTest.php
    - tests/Feature/SupplierProductionUpdateTest.php
  modified:
    - app/Domain/Planning/Models/ProductionScheduleEntry.php
    - app/Domain/Planning/Models/ProductionSchedule.php
    - database/seeders/SupplierPortalRolesSeeder.php

key-decisions:
  - "Portal EntriesRelationManager is a new class separate from admin EntriesRelationManager — prevents accidental exposure of create/delete on supplier side"
  - "actual_quantity uses integer cast (not nullable cast) — Eloquent handles null transparently; cast ensures int when value present"
  - "Permission::firstOrCreate() in test setUp instead of seeder dependency — keeps tests hermetic"

patterns-established:
  - "Supplier Portal resource: tenantOwnershipRelationshipName = supplierCompany on all resources for multi-tenant scoping"
  - "Null-safe actual quantity: always use ($e->actual_quantity ?? 0) in sum — never use falsy check (0 is valid reported value)"

requirements-completed: [PROD-01]

# Metrics
duration: 5min
completed: 2026-03-11
---

# Phase 1 Plan 01: Production Control Foundation Summary

**actual_quantity column added to production_schedule_entries with Supplier Portal resource for reporting daily production actuals via tenant-scoped Filament UI**

## Performance

- **Duration:** ~5 min
- **Started:** 2026-03-11T08:04:39Z
- **Completed:** 2026-03-11T08:08:59Z
- **Tasks:** 2
- **Files modified:** 10

## Accomplishments

- Migration adds `actual_quantity` nullable integer to `production_schedule_entries` (additive, zero-downtime)
- `ProductionSchedule` model gains `getTotalActualQuantityAttribute` and `getShipmentReadyQuantityByItem()` accessors
- Supplier Portal `ProductionScheduleResource` scoped to supplier tenant via `supplierCompany` relationship
- Portal `EntriesRelationManager` exposes only `actual_quantity` for editing — all planned fields read-only
- Two new Spatie permissions registered and assigned to `supplier_full` and `supplier_operations` roles

## Task Commits

Each task was committed atomically:

1. **TDD RED - failing tests** - `39189a1` (test)
2. **Task 1: Migration, model updates, and unit tests** - `4f4c07f` (feat)
3. **Task 2: Supplier Portal ProductionScheduleResource** - `12ea26a` (feat)

## Files Created/Modified

- `database/migrations/2026_03_11_100000_add_actual_quantity_to_production_schedule_entries.php` - Additive migration adding actual_quantity nullable integer
- `app/Domain/Planning/Models/ProductionScheduleEntry.php` - Added actual_quantity to fillable and integer cast
- `app/Domain/Planning/Models/ProductionSchedule.php` - Added getTotalActualQuantityAttribute and getShipmentReadyQuantityByItem accessors
- `app/Filament/SupplierPortal/Resources/ProductionScheduleResource.php` - Supplier Portal resource with tenant scoping
- `app/Filament/SupplierPortal/Resources/ProductionScheduleResource/Pages/ListProductionSchedules.php` - List page
- `app/Filament/SupplierPortal/Resources/ProductionScheduleResource/Pages/ViewProductionSchedule.php` - View page with EntriesRelationManager
- `app/Filament/SupplierPortal/Resources/ProductionScheduleResource/RelationManagers/EntriesRelationManager.php` - Portal-only entry editor (actual_quantity only)
- `database/seeders/SupplierPortalRolesSeeder.php` - Added view-production-schedules and update-production-actuals permissions
- `tests/Unit/ProductionScheduleActualTest.php` - 10 unit tests covering all model behaviors
- `tests/Feature/SupplierProductionUpdateTest.php` - 13 feature tests covering persistence, planned-field immutability, and tenant isolation

## Decisions Made

- Created a new portal-specific `EntriesRelationManager` (not reusing the admin one) to ensure the supplier sees only `actual_quantity` as editable — no create/delete exposure
- Used `integer` cast for `actual_quantity` (not explicitly `nullable:integer`) because Eloquent handles null transparently; cast only applies when value is present
- Feature tests use `Permission::firstOrCreate()` in test setup for hermetic tests that don't depend on seeders

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

None.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- `actual_quantity` column exists and models are ready for Plan 02 (production comparison logic)
- Supplier Portal resource is live and auto-discovered by SupplierPortalPanelProvider
- `getShipmentReadyQuantityByItem()` accessor ready for Plan 03 (shipment-ready quantity calculations)
- All 99 tests pass (no regressions)

---
*Phase: 01-production-control*
*Completed: 2026-03-11*
