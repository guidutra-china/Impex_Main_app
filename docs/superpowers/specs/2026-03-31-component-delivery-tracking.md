# Component Delivery Tracking & Client Portal Grid Redesign
Date: 2026-03-31

## Overview

Three changes:
1. Add delivery tracking per component (expected dates + received quantities)
2. Redesign Supplier Portal component panel as a grid (dates as columns)
3. Redesign Client Portal production progress as a single grid (dates as columns, no collapsing) with component visibility

## Data Model

### New table: `component_deliveries`

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `production_schedule_component_id` | FK | cascadeOnDelete |
| `expected_date` | date | When the delivery is expected |
| `expected_qty` | integer | Qty supplier expects to receive |
| `received_qty` | integer nullable | Qty actually received (null = not yet received) |
| `received_date` | date nullable | When actually received |
| `notes` | text nullable | |
| `timestamps` | | |

### Modified: `production_schedule_components`

Add column:
- `quantity_required` (integer, not null) — calculated as BOM qty × PI qty. Set on auto-populate, not editable by supplier.

Remove manual add/delete capability from supplier — components come exclusively from product BOM.

### Calculation

```
quantity_required = product_component.quantity_required × proforma_invoice_item.quantity
```

Example: BOM says "1 LCD per unit", PI has 100 units → `quantity_required = 100`

## Supplier Portal — Component Delivery Grid

Replaces the current ComponentInventoryPanel. New Livewire component: `ComponentDeliveryGrid`.

### Layout

```
┌─────────────┬────────┬───────┬───────┬───────┬───────┬───────────┐
│ Component    │ Needed │ 10/04 │ 17/04 │ 24/04 │ 01/05 │ Progress  │
├─────────────┼────────┼───────┼───────┼───────┼───────┼───────────┤
│ LCD Panel    │  100   │  25✓  │ [25]  │ [25]  │ [25]  │ ██░░ 25%  │
│ PCB Board    │  200   │  50✓  │ [50]  │  [50] │ [50]  │ ██░░ 25%  │
│ Power Supply │  100   │ 100✓  │   —   │   —   │   —   │ ████ 100% │
└─────────────┴────────┴───────┴───────┴───────┴───────┴───────────┘
                                              [Add Date] [columns btn]
```

### Behavior

- **Rows** = components (from BOM, not editable)
- **Columns** = delivery dates (supplier adds/removes dates)
- **Cells** = expected_qty (editable input when not yet received) or received_qty + ✓ (when marked received)
- **Needed** = `quantity_required` (calculated, read-only)
- **Progress** = sum(received_qty) / quantity_required
- **Add Date** button adds a new delivery date column
- Supplier can remove a date column (deletes its delivery entries)
- **Mark received**: supplier clicks a cell that has expected_qty → opens small confirm with received_qty (defaults to expected_qty) → marks as received
- Editable only when schedule status is `draft` or `rejected`

### No manual component add/delete

Components come from product BOM only. The auto-populate action copies them when the schedule is first created. Supplier cannot add or remove components.

## Client Portal — Production Progress Redesign

Replaces the current `pi-production-progress.blade.php` with a grid layout.

### Layout

```
PRODUCTION PROGRESS
┌─────────────┬────────┬───────┬───────┬───────┬───────┬───────────┐
│ Product      │ PI Qty │ 10/04 │ 17/04 │ 24/04 │ 01/05 │ Progress  │
├─────────────┼────────┼───────┼───────┼───────┼───────┼───────────┤
│ TV 55"       │  100   │ 20/25 │  —/25 │  —/25 │  —/25 │ ██░░ 20%  │
│ TV 43"       │   50   │ 10/15 │  —/15 │  —/20 │   —   │ ██░░ 20%  │
└─────────────┴────────┴───────┴───────┴───────┴───────┴───────────┘

COMPONENTS
┌─────────────┬────────┬───────┬───────┬───────┬───────┬───────────┐
│ Component    │ Needed │ 10/04 │ 17/04 │ 24/04 │ 01/05 │ Progress  │
├─────────────┼────────┼───────┼───────┼───────┼───────┼───────────┤
│ LCD Panel    │  100   │  25✓  │   25  │   25  │   25  │ ██░░ 25%  │
│ PCB Board    │  200   │  50✓  │   50  │   50  │   50  │ ██░░ 25%  │
└─────────────┴────────┴───────┴───────┴───────┴───────┴───────────┘
```

### Behavior

- All products in one table (no collapsing/expanding)
- Dates as columns (from production schedule entries)
- Cell format: `actual/planned` — actual is bold if > 0, gray dash if null
- Progress bar per row
- Components section below — same grid format, read-only
- Cell format for components: `received_qty ✓` or `expected_qty` (plain)
- Approval widget stays below both grids (for pending_approval schedules)

## Files to Create

| File | Purpose |
|------|---------|
| `database/migrations/2026_03_31_110000_add_quantity_required_to_production_schedule_components.php` | Add qty column |
| `database/migrations/2026_03_31_120000_create_component_deliveries_table.php` | Deliveries table |
| `app/Domain/Planning/Models/ComponentDelivery.php` | New model |
| `app/Livewire/SupplierPortal/ComponentDeliveryGrid.php` | Replaces ComponentInventoryPanel |
| `resources/views/livewire/supplier-portal/component-delivery-grid.blade.php` | Grid view |
| `resources/views/filament/supplier-portal/component-delivery-grid-entry.blade.php` | ViewEntry wrapper |
| `tests/Feature/Livewire/SupplierPortal/ComponentDeliveryGridTest.php` | Tests |

## Files to Modify

| File | Change |
|------|--------|
| `app/Domain/Planning/Models/ProductionScheduleComponent.php` | Add `quantity_required` fillable, add `deliveries()` HasMany |
| `app/Domain/Planning/Actions/PopulateScheduleComponentsFromProductAction.php` | Calculate and set `quantity_required` |
| `app/Filament/SupplierPortal/Resources/ProductionScheduleResource.php` | Replace component panel ViewEntry with delivery grid |
| `resources/views/portal/infolists/pi-production-progress.blade.php` | Full rewrite: grid layout with dates as columns + components section |

## Files to Delete

| File | Reason |
|------|--------|
| `app/Livewire/SupplierPortal/ComponentInventoryPanel.php` | Replaced by ComponentDeliveryGrid |
| `resources/views/livewire/supplier-portal/component-inventory-panel.blade.php` | Replaced |
| `resources/views/filament/supplier-portal/component-inventory-panel-entry.blade.php` | Replaced |
| `tests/Feature/Livewire/SupplierPortal/ComponentInventoryPanelTest.php` | Replaced |

## Out of Scope

- Email notifications for component deliveries
- Component delivery import from Excel
- Mobile-specific layout
- Editing BOM from supplier portal (admin only)
