# Production Schedule Redesign — Design Spec
Date: 2026-03-30

## Overview

Redesign the Production Schedule feature with three goals:
1. Allow suppliers to create and manage their own production agenda via a modern Livewire grid
2. Introduce a client approval flow before production begins
3. Add a component/parts inventory per schedule, with risk indicators on the production grid

---

## Workflow

```
Supplier creates agenda (draft)
  → Supplier submits for approval
    → Client reviews & approves or rejects
      → If rejected: supplier revises → resubmits
      → If approved: production begins
        → Admin enters actual quantities daily
          → When 100% complete: auto-mark as completed
```

### Roles per status

| Actor | Action |
|-------|--------|
| Supplier | Creates schedule (draft), edits entries, manages components, submits |
| Client | Reviews read-only grid, approves or rejects with optional note |
| Admin | Full visibility, inserts `actual_quantity`, can force any transition |

---

## Status Flow

`draft` → `pending_approval` → `approved` → `completed`

With rejection path: `pending_approval` → `rejected` → back to `draft` (supplier revises)

---

## Data Model Changes

### `production_schedules` — new columns

| Column | Type | Notes |
|--------|------|-------|
| `status` | enum | `draft`, `pending_approval`, `approved`, `rejected`, `completed` — default `draft` |
| `submitted_at` | timestamp nullable | When supplier submitted for approval |
| `approved_by` | FK users nullable | Who approved or rejected |
| `approved_at` | timestamp nullable | When approved/rejected |
| `approval_notes` | text nullable | Client rejection reason |

### `production_schedule_components` — new table

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `production_schedule_id` | FK | cascadeOnDelete |
| `proforma_invoice_item_id` | FK | which product this component belongs to |
| `component_name` | string nullable | NULL = product-level status; non-null = named sub-component |
| `status` | enum | `at_factory`, `in_transit`, `at_supplier` |
| `supplier_name` | string nullable | Third-party supplier name (when `at_supplier`) |
| `eta` | date nullable | Expected arrival at factory |
| `notes` | text nullable | |
| `updated_by` | FK users nullable | nullOnDelete |
| `timestamps` | | |

**Key rule:** `component_name = null` means a product-level status (simple view). `component_name = "Painel LCD"` means an expanded sub-component. A product can have one null-name row (overall status) plus N named rows (components).

---

## New Models

### `ProductionScheduleComponent`
- Fillable: `production_schedule_id`, `proforma_invoice_item_id`, `component_name`, `status`, `supplier_name`, `eta`, `notes`, `updated_by`
- Relationships: `belongsTo ProductionSchedule`, `belongsTo ProformaInvoiceItem`, `belongsTo User (updatedBy)`
- Enum: `ComponentStatus` with cases `at_factory`, `in_transit`, `at_supplier`

### `ProductionSchedule` — new relationship
- `components()`: HasMany `ProductionScheduleComponent`
- New methods: `hasComponentRisk(): bool`, `componentRiskDates(): array` (returns dates where a component ETA > production date)

---

## Livewire Components

### 1. `ProductionScheduleGrid`
**Location:** `app/Livewire/SupplierPortal/ProductionScheduleGrid.php`
**View:** `resources/views/livewire/supplier-portal/production-schedule-grid.blade.php`
**Portal:** Supplier

Responsibilities:
- Renders grid: rows = PI items (products), columns = production dates
- Each cell shows planned `quantity` (editable input when status = `draft`)
- Column header shows date; can add new date columns (opens date picker)
- Footer row: totals per date column
- "Saldo" column: total planned vs PI qty (green = ok, red = under)
- ⚠️ indicator on cells where component ETA > that date (from `componentRiskDates()`)
- "Submeter para Aprovação" button — validates all PI items have planned qty ≥ PI qty, then transitions to `pending_approval`
- Locked (read-only) when status ≠ `draft`
- Auto-save on cell blur via Livewire `wire:model.blur`

### 2. `ComponentInventoryPanel`
**Location:** `app/Livewire/SupplierPortal/ComponentInventoryPanel.php`
**View:** `resources/views/livewire/supplier-portal/component-inventory-panel.blade.php`
**Portal:** Supplier

Responsibilities:
- Collapsible section below the production grid
- Lists each PI item (product) with its product-level component status badge
- Expand per product to see/add/edit named sub-components
- Inline CRUD: add component row (name, status, supplier_name, eta), edit, delete
- Editable when status = `draft` or `rejected`; read-only otherwise
- Status badges: green (`at_factory`), yellow (`in_transit`), red (`at_supplier`)
- Emits event to `ProductionScheduleGrid` when component ETA changes (to refresh ⚠️ indicators)

### 3. `ScheduleApprovalWidget`
**Location:** `app/Livewire/Portal/ScheduleApprovalWidget.php`
**View:** `resources/views/livewire/portal/schedule-approval-widget.blade.php`
**Portal:** Client

Responsibilities:
- Shown only when status = `pending_approval`
- Renders read-only version of the production grid (same layout, no inputs)
- Shows supplier submission date
- Textarea for approval note (required on rejection, optional on approval)
- Two buttons: "Aprovar Agenda" (→ `approved`) and "Rejeitar" (→ `rejected`)
- On approve: sets `approved_by`, `approved_at`, transitions status
- On reject: requires non-empty note, saves to `approval_notes`, transitions status

### 4. `ProductionActualsGrid`
**Location:** `app/Livewire/Admin/ProductionActualsGrid.php`
**View:** `resources/views/livewire/admin/production-actuals-grid.blade.php`
**Portal:** Admin

Responsibilities:
- Shown in admin ProductionSchedule view page when status = `approved`
- Same grid layout as supplier grid
- Each cell shows: planned qty (top, gray) + actual qty input (bottom, editable)
- Today's column highlighted in blue
- Past dates with actuals: green if actual ≥ plan, yellow if actual < plan
- "Salvar Realizados" button — saves all dirty cells
- After save: calls existing `UpdatePaymentScheduleFromProductionAction`
- Auto-transitions to `completed` when sum(actual_quantity) ≥ sum(quantity) across all entries

---

## UI Layout

### Supplier Portal — ViewProductionSchedule page

```
[Header: reference + status badge + PI reference]
[ProductionScheduleGrid Livewire component]
[ComponentInventoryPanel Livewire component — collapsible]
```

### Client Portal — ViewProformaInvoice page (existing pi-production-progress view)

```
[Existing production progress section — updated to show new status]
[ScheduleApprovalWidget — visible only when pending_approval]
```

### Admin — ViewProductionSchedule page

```
[Existing infolist sections]
[ProductionActualsGrid — visible only when approved or completed]
[Existing EntriesRelationManager — kept for detailed management]
```

---

## Grid UX Details

- **Layout:** rows = products (name + SKU), columns = dates (sortable, addable by supplier)
- **Saldo column:** `total_planned - pi_qty` — green if ≥ 0, red if < 0
- **Cell ⚠️:** shown when any component for that product has `eta > production_date`
- **Validation before submit:** all products must have `total_planned ≥ pi_qty`; error shown inline
- **Read-only state:** inputs replaced by plain text; submit button hidden
- **Auto-save:** `wire:model.blur` on each input — saves entry on focus-out, no full page reload

---

## Permissions

| Permission | Actor | Action |
|------------|-------|--------|
| `supplier-portal:manage-production-schedule` | Supplier | Create/edit entries and components when draft |
| `supplier-portal:submit-production-schedule` | Supplier | Submit for approval |
| `portal:approve-production-schedule` | Client | Approve or reject |
| `manage-production-actuals` | Admin | Insert actual quantities |

---

## Files to Create

| File | Purpose |
|------|---------|
| `database/migrations/XXXX_add_status_fields_to_production_schedules.php` | Status + approval columns |
| `database/migrations/XXXX_create_production_schedule_components_table.php` | New components table |
| `app/Domain/Planning/Models/ProductionScheduleComponent.php` | New model |
| `app/Domain/Planning/Enums/ProductionScheduleStatus.php` | New enum (replaces or supplements existing if any) |
| `app/Domain/Planning/Enums/ComponentStatus.php` | `at_factory`, `in_transit`, `at_supplier` |
| `app/Livewire/SupplierPortal/ProductionScheduleGrid.php` | Livewire grid — supplier |
| `app/Livewire/SupplierPortal/ComponentInventoryPanel.php` | Livewire components panel |
| `app/Livewire/Portal/ScheduleApprovalWidget.php` | Livewire approval widget — client |
| `app/Livewire/Admin/ProductionActualsGrid.php` | Livewire actuals grid — admin |
| `resources/views/livewire/supplier-portal/production-schedule-grid.blade.php` | Grid template |
| `resources/views/livewire/supplier-portal/component-inventory-panel.blade.php` | Components template |
| `resources/views/livewire/portal/schedule-approval-widget.blade.php` | Approval template |
| `resources/views/livewire/admin/production-actuals-grid.blade.php` | Actuals template |

## Files to Modify

| File | Change |
|------|--------|
| `app/Domain/Planning/Models/ProductionSchedule.php` | Add `components()`, `hasComponentRisk()`, `componentRiskDates()`, fillable for new columns |
| `app/Filament/SupplierPortal/Resources/ProductionScheduleResource/Pages/ViewProductionSchedule.php` | Replace EntriesRelationManager with Livewire grid + component panel |
| `app/Filament/Portal/Resources/ProformaInvoiceResource.php` | Add ScheduleApprovalWidget to PI view |
| `app/Filament/Resources/ProductionSchedules/Pages/ViewProductionSchedule.php` | Add ProductionActualsGrid |
| `app/Filament/SupplierPortal/Resources/ProductionScheduleResource.php` | Add create/edit permissions for draft schedules |

---

## Out of Scope

- Email/push notifications when status changes (can be a follow-up)
- Gantt chart visualization
- Bulk import from Excel (existing feature — unchanged)
- Mobile-specific layout optimizations
