# Phase 4: Redesign Production Schedule UX - Research

**Researched:** 2026-03-31
**Domain:** Laravel 12 / Filament 4 / Livewire 3 — Supplier Portal UX, Eloquent model extensions, approval state machines, component inventory management, admin risk aggregation
**Confidence:** HIGH

---

## Summary

Phase 4 is an extension and UX redesign of Phase 1 work. The data model is largely in place — `production_schedules`, `production_schedule_entries`, and `production_schedule_components` tables all exist with migrations already run. The primary gaps are: (1) no `ProductionScheduleComponent` Eloquent model, (2) no portal UI for component management, (3) the `status/submitted_at/approved_by/approved_at/approval_notes` columns added in `2026_03_30_110000` migration are orphaned — the `ProductionSchedule` model does not expose them in `$fillable`, has no casts for them, and has no state-transition logic, (4) the supplier portal view page shows a plain table — no calendar/timeline, no "confirm today" flow, and no approval actions.

The stack is **Filament 4.7.2 + Livewire 3.7.10 + Laravel 12.52.0**. Filament 4 is the current generation, departed significantly from v3 — it uses `filament/schemas` as the unifying layer for forms, infolists, and table schemas. The project already uses custom `Page` subclasses (OrderPipelineKanban, CompareSupplierQuotations, ConductAudit) and Blade view partials (`ViewEntry`) as established patterns, so similar approaches are appropriate here. No external JavaScript calendar library is needed — a well-structured Blade partial or Livewire component rendering HTML/CSS grid layouts is the right fit for this application's style.

The approval workflow pattern must be a PHP state machine on the model using a string `status` enum approach — consistent with other enums already in `app/Domain/Infrastructure/Enums/`. The risk aggregation dashboard for admin must be a Filament `StatsOverviewWidget` or a custom `Page` rendering aggregated component data — same pattern as `SupplierOverviewWidget` and `OrderPipelineKanban`.

**Primary recommendation:** Build incrementally in this order — (1) ProductionScheduleComponent model + portal CRUD, (2) approval state machine on ProductionSchedule, (3) enhanced portal view with "confirm today" action and calendar/timeline Blade partial, (4) admin risk aggregation widget or page. Each increment is independently testable and delivers value.

---

## Project Constraints (from CLAUDE.md)

- Follow Domain-Driven Design with bounded contexts — new models go in `app/Domain/Planning/Models/`
- Keep files under 500 lines
- Use typed interfaces for all public APIs
- Prefer TDD London School (mock-first) for new code
- Use event sourcing for state changes
- Ensure input validation at system boundaries
- NEVER save working files or tests to the root folder — tests go in `/tests/Feature/` or `/tests/Unit/`
- ALWAYS run tests after code changes; ALWAYS verify build succeeds before committing
- NEVER hardcode API keys, secrets, or credentials in source files

---

## Standard Stack

### Core (already installed — no new packages needed)

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| filament/filament | 4.7.2 | Resource pages, Infolists, Tables, Actions | Project baseline |
| livewire/livewire | 3.7.10 | Reactive UI, inline form components | Bundled with Filament 4 |
| laravel/framework | 12.52.0 | Base framework, Eloquent, migrations | Project baseline |
| spatie/laravel-activitylog | 4.12 | Audit trail for status changes | Already used on ProductionSchedule |
| bezhansalleh/filament-shield | 4.1.0 | Permission gating on portal actions | Already used for supplier-portal:* permissions |

### No New Packages Required

All required functionality can be implemented with the current stack:
- Calendar/timeline view: HTML/CSS grid layout in a Blade partial (same approach as `pi-production-progress.blade.php`)
- State machine: PHP enum + model method pattern (no external FSM library needed at this scale)
- Risk aggregation: Filament `StatsOverviewWidget` or custom `Page` (same as `SupplierOverviewWidget`)

**Installation:** None required.

---

## Architecture Patterns

### Recommended Project Structure

New files for this phase:

```
app/Domain/Planning/
├── Models/
│   ├── ProductionScheduleComponent.php       # NEW — Eloquent model for existing table
├── Enums/
│   ├── ProductionScheduleStatus.php          # NEW — draft/submitted/approved/rejected
│   ├── ComponentStatus.php                   # NEW — at_supplier/at_factory/in_transit
├── Actions/
│   ├── SubmitProductionScheduleAction.php    # NEW — supplier submits for approval
│   ├── ApproveProductionScheduleAction.php   # NEW — admin approves schedule
│   └── RejectProductionScheduleAction.php    # NEW — admin rejects with notes

app/Filament/SupplierPortal/Resources/ProductionScheduleResource/
├── Pages/
│   └── ViewProductionSchedule.php            # EDIT — add confirm-today action + submit action
├── RelationManagers/
│   ├── EntriesRelationManager.php            # EDIT — add today-filter context
│   └── ComponentsRelationManager.php         # NEW — component CRUD for portal

app/Filament/Resources/ProductionSchedules/
├── Pages/
│   └── ViewProductionSchedule.php            # EDIT — add approve/reject actions
├── RelationManagers/
│   └── ComponentsRelationManager.php         # NEW — admin component view with risk flags
├── Widgets/
│   └── ComponentRiskWidget.php               # NEW — admin risk aggregation

resources/views/filament/production-schedule/
├── calendar-timeline.blade.php               # NEW — calendar/timeline view partial
├── confirm-today-summary.blade.php           # NEW — today's entries summary card
```

### Pattern 1: Eloquent Model for Existing Table (ProductionScheduleComponent)

**What:** Add the missing model to bridge the `production_schedule_components` table.
**When to use:** Table exists with FK constraints already defined in migration; model is the only gap.

```php
// app/Domain/Planning/Models/ProductionScheduleComponent.php
namespace App\Domain\Planning\Models;

use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionScheduleComponent extends Model
{
    protected $fillable = [
        'production_schedule_id',
        'proforma_invoice_item_id',
        'component_name',
        'status',
        'supplier_name',
        'eta',
        'notes',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'eta' => 'date',
        ];
    }

    public function productionSchedule(): BelongsTo
    {
        return $this->belongsTo(ProductionSchedule::class);
    }

    public function proformaInvoiceItem(): BelongsTo
    {
        return $this->belongsTo(ProformaInvoiceItem::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
```

Add the inverse relation on `ProductionSchedule`:

```php
public function components(): HasMany
{
    return $this->hasMany(ProductionScheduleComponent::class);
}
```

### Pattern 2: Status Enum for Approval Workflow

**What:** PHP-backed string enum for `production_schedules.status`.
**When to use:** When a column has a fixed set of values and needs label/color helpers.

```php
// app/Domain/Planning/Enums/ProductionScheduleStatus.php
namespace App\Domain\Planning\Enums;

enum ProductionScheduleStatus: string
{
    case DRAFT      = 'draft';
    case SUBMITTED  = 'submitted';
    case APPROVED   = 'approved';
    case REJECTED   = 'rejected';

    public function label(): string
    {
        return match($this) {
            self::DRAFT     => 'Draft',
            self::SUBMITTED => 'Submitted for Approval',
            self::APPROVED  => 'Approved',
            self::REJECTED  => 'Rejected',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::DRAFT     => 'gray',
            self::SUBMITTED => 'warning',
            self::APPROVED  => 'success',
            self::REJECTED  => 'danger',
        };
    }
}
```

Update `ProductionSchedule.$fillable` to include the new status columns and add enum cast:

```php
protected $fillable = [
    // existing fields...
    'status', 'submitted_at', 'approved_by', 'approved_at', 'approval_notes',
];

protected function casts(): array
{
    return [
        'received_date'  => 'date',
        'version'        => 'integer',
        'status'         => ProductionScheduleStatus::class,
        'submitted_at'   => 'datetime',
        'approved_at'    => 'datetime',
    ];
}
```

### Pattern 3: Action Classes for State Transitions (DDD)

**What:** Discrete Action classes per transition, not model methods. Consistent with `UpdatePaymentScheduleFromProductionAction`.
**When to use:** Any state-changing operation that may have side effects (notifications, audit logs).

```php
// app/Domain/Planning/Actions/SubmitProductionScheduleAction.php
namespace App\Domain\Planning\Actions;

use App\Domain\Planning\Enums\ProductionScheduleStatus;
use App\Domain\Planning\Models\ProductionSchedule;

class SubmitProductionScheduleAction
{
    public function execute(ProductionSchedule $schedule): void
    {
        if ($schedule->status !== ProductionScheduleStatus::DRAFT) {
            throw new \RuntimeException('Only draft schedules can be submitted.');
        }

        $schedule->update([
            'status'       => ProductionScheduleStatus::SUBMITTED,
            'submitted_at' => now(),
        ]);
        // activity log fires automatically via LogsActivity trait
    }
}
```

### Pattern 4: "Confirm Today" Supplier Flow

**What:** A Filament `Action` on the portal ViewProductionSchedule page that shows today's planned entries in a modal with `actual_quantity` inputs.
**When to use:** When the supplier has entries for today's date and wants to bulk-confirm production in one step, rather than editing each row individually.

Key implementation detail: the action filters entries where `production_date = today()` and builds a repeatable form group. After save, calls `UpdatePaymentScheduleFromProductionAction`.

```php
// Inside portal ViewProductionSchedule::getHeaderActions()
Action::make('confirmToday')
    ->label('Confirm Today\'s Production')
    ->icon('heroicon-o-check-circle')
    ->color('success')
    ->visible(fn () => $this->record->entries()
        ->where('production_date', today())
        ->exists())
    ->form(fn () => $this->record->entries()
        ->where('production_date', today())
        ->get()
        ->map(fn ($entry) => TextInput::make("entry_{$entry->id}")
            ->label($entry->purchaseOrderItem?->product?->name ?? $entry->proformaInvoiceItem?->description ?? "Entry #{$entry->id}")
            ->helperText("Planned: {$entry->quantity}")
            ->numeric()
            ->minValue(0)
            ->default($entry->actual_quantity)
        )->all()
    )
    ->action(function (array $data) {
        foreach ($data as $key => $value) {
            if (str_starts_with($key, 'entry_')) {
                $entryId = (int) str_replace('entry_', '', $key);
                ProductionScheduleEntry::find($entryId)?->update(['actual_quantity' => $value]);
            }
        }
        app(UpdatePaymentScheduleFromProductionAction::class)->execute($this->record);
        Notification::make()->title('Production confirmed')->success()->send();
    });
```

### Pattern 5: Calendar/Timeline Blade Partial

**What:** An HTML/CSS grid rendering production entries as a calendar strip — dates as columns, products as rows. Uses inline Tailwind classes consistent with the existing `pi-production-progress.blade.php` style.
**When to use:** Rendering structured time-series data in the portal view without any JavaScript dependency.

The partial receives `$schedule` and renders a horizontal scrollable div with a CSS grid. Days where `actual_quantity` has been entered are colored green/yellow/red. This is a `ViewEntry` in the Filament infolist, same as `shipment-ready-summary.blade.php`.

### Pattern 6: Admin Approval Actions on ViewProductionSchedule

**What:** `Action::make('approve')` and `Action::make('reject')` added to `getHeaderActions()` on the admin `ViewProductionSchedule` page. Visible only when `status === SUBMITTED`. Uses `ModalAction` with a textarea for rejection notes.
**When to use:** When an admin needs to approve/reject a supplier-submitted schedule from the admin panel.

### Pattern 7: ComponentsRelationManager

**What:** A standard Filament `RelationManager` for `components()` relation on both portal and admin resources.
**When to use:** CRUD for the `production_schedule_components` table, scoped to a specific schedule.

Portal version: can create/edit components (suppliers enter component status), cannot delete.
Admin version: read-only with risk flag column (ETA past-due highlighted in danger color).

### Anti-Patterns to Avoid

- **Adding Livewire-specific JS calendar libraries (FullCalendar, etc.):** Overkill for this use case; plain HTML/CSS is sufficient and matches the existing Blade partial style.
- **Model observers for state transitions:** Phase 1 explicitly rejected observers in favor of explicit Action classes (`[Phase 01-03]` decision). Continue this pattern.
- **Per-item threshold checks for payment readiness:** `[Phase 01-03]` decision — readiness is at PI level, not per-item. Do not change this.
- **Separate status flag/boolean columns:** The `status` string column (already migrated) handles all states. Do not add `is_approved`, `is_submitted` booleans.
- **Fat ViewRecord pages over 500 lines:** Extract actions into dedicated classes per CLAUDE.md file size limit.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Activity logging for approvals | Custom audit table | `spatie/laravel-activitylog` (already on model) | LogsActivity trait fires automatically on all model changes |
| Permission checks on portal actions | Custom middleware/gates | Filament's `->visible(fn () => auth()->user()?->can(...))` | Already used in `EntriesRelationManager` EditAction |
| Calendar grid CSS | JavaScript library | Tailwind responsive grid in Blade partial | Existing style precedent; no new JS dep needed |
| Notification delivery | Custom notification table | `Filament\Notifications\Notification::make()...->send()` | Already used in `ViewProductionSchedule` import action |
| Multi-step form/wizard for confirm-today | Custom multi-page flow | Filament modal `Action` with `->form([...])` | Already used in the admin `importSpreadsheetAction()` |

**Key insight:** The existing codebase has established patterns for every capability needed in Phase 4. The goal is consistent application of those patterns, not introduction of new libraries.

---

## Common Pitfalls

### Pitfall 1: Missing $fillable and Casts for Migrated Columns

**What goes wrong:** The `status`, `submitted_at`, `approved_by`, `approved_at`, `approval_notes` columns are in the database (migration `2026_03_30_110000`) but NOT in `ProductionSchedule.$fillable` or `casts()`. Any attempt to mass-assign or read these as typed values will silently fail or return raw strings.
**Why it happens:** The migration was written speculatively without the model update being committed.
**How to avoid:** Update `$fillable` and `casts()` in the same task/commit as the migration change.
**Warning signs:** `$schedule->status` returns a string instead of a `ProductionScheduleStatus` enum; `$schedule->update(['status' => 'submitted'])` has no effect silently if `status` not in `$fillable`.

### Pitfall 2: EntriesRelationManager Query Filter Scope

**What goes wrong:** The portal `EntriesRelationManager` filters with `->whereNotNull('purchase_order_item_id')`. If entries for a schedule have no PO linkage (PI-only), they will be invisible to the supplier. A "confirm today" flow built on top of this query will miss those entries.
**Why it happens:** The filter was intentionally added to scope to PO-linked entries for the payment trigger, but silently excludes PI-only entries.
**How to avoid:** The confirm-today action should query `entries()` directly (unfiltered) or explicitly account for both PI-only and PO-linked entries. Document this distinction clearly.
**Warning signs:** Today's entries visible in admin view but not appearing in supplier confirm-today modal.

### Pitfall 3: Filament 4 API Differences from v3 Docs

**What goes wrong:** Filament 4 (current: 4.7.2) changed `Schema` handling significantly. Forms, infolists, and tables all use `Filament\Schemas\Schema`. Online tutorials referencing Filament v3 use `Forms\Schema`, `Tables\Table`, `Infolists\Schema` as separate classes with different method signatures.
**Why it happens:** Most Google results and Stack Overflow answers are for Filament v3.
**How to avoid:** Follow the patterns in existing project files exactly. Key verified import: `use Filament\Schemas\Schema;` is correct (not `Filament\Forms\Form`). The `RelationManager::form(Schema $schema)` signature is confirmed in the existing code.
**Warning signs:** IDE type errors on `Schema` parameter; methods not found at runtime.

### Pitfall 4: Tenant Isolation in Portal ComponentsRelationManager

**What goes wrong:** The supplier portal uses `$tenantOwnershipRelationshipName = 'supplierCompany'` on `ProductionScheduleResource`. A `ComponentsRelationManager` attached to this resource automatically inherits the tenant scope for the parent record, but if `ProductionScheduleComponent` is accessed via a custom query outside the relation manager context, tenant scoping may be bypassed.
**Why it happens:** Filament's multi-tenancy scoping applies to the owner resource record, not automatically to nested models.
**How to avoid:** Always access components via `$schedule->components()` (relation manager scopes correctly). In any custom query, add `->whereHas('productionSchedule', fn ($q) => $q->where('supplier_company_id', $tenantId))`.
**Warning signs:** A supplier can see component data from another supplier's schedule.

### Pitfall 5: Status Guard on Approval Actions

**What goes wrong:** If `approve()` and `reject()` actions do not guard on `status === SUBMITTED`, an admin could approve an already-approved schedule (idempotency violation) or approve a draft that was never submitted.
**Why it happens:** Action visibility in Filament UI is separate from the Action's internal guard. Even with `->visible(fn () => ...)`, the underlying action can still be triggered via direct POST if checks are only in the view layer.
**How to avoid:** Add explicit status guards in the Action class execute method (not just `->visible()`), consistent with the `SubmitProductionScheduleAction` pattern above.
**Warning signs:** `approved_at` getting set on a schedule with `status=draft`.

### Pitfall 6: N+1 Queries in Calendar Blade Partial

**What goes wrong:** Rendering a calendar grid that iterates over entries and accesses `entry->proformaInvoiceItem->product->name` for each cell will fire one query per entry.
**Why it happens:** Lazy loading is the default in Laravel unless eager loading is explicitly added.
**How to avoid:** Eager load before passing to the Blade partial: `$schedule->load('entries.proformaInvoiceItem.product', 'components.proformaInvoiceItem')`.
**Warning signs:** Slow page load for schedules with many entries; Laravel Debugbar showing 50+ queries.

---

## Code Examples

Verified patterns from existing project files:

### Filament 4 RelationManager with HasMany

```php
// Source: app/Filament/SupplierPortal/Resources/ProductionScheduleResource/RelationManagers/EntriesRelationManager.php
class ComponentsRelationManager extends RelationManager
{
    protected static string $relationship = 'components';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('status')
                ->options(ComponentStatus::class)
                ->required(),
            // ...
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([/* ... */])
            ->recordActions([
                EditAction::make()
                    ->visible(fn () => auth()->user()?->can('supplier-portal:update-production-actuals') ?? false),
            ]);
    }
}
```

### Filament 4 Modal Action on ViewRecord Page

```php
// Source: app/Filament/Resources/ProductionSchedules/Pages/ViewProductionSchedule.php
protected function getHeaderActions(): array
{
    return [
        Action::make('approve')
            ->label('Approve Schedule')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn () => $this->record->status === ProductionScheduleStatus::SUBMITTED)
            ->requiresConfirmation()
            ->action(function () {
                app(ApproveProductionScheduleAction::class)->execute($this->record, auth()->user());
                Notification::make()->title('Schedule approved')->success()->send();
                $this->refreshFormData(['status']);
            }),
    ];
}
```

### ViewEntry Blade Partial (confirmed pattern from project)

```php
// Source: app/Filament/Resources/ProductionSchedules/Schemas/ProductionScheduleInfolist.php
ViewEntry::make('calendar_timeline')
    ->label('Production Calendar')
    ->view('filament.production-schedule.calendar-timeline')
    ->columnSpanFull(),
```

### DDD Action Class pattern (from UpdatePaymentScheduleFromProductionAction)

```php
// New actions follow the same interface: single execute() method, receives model, returns typed result
class ApproveProductionScheduleAction
{
    public function execute(ProductionSchedule $schedule, User $approver): void
    {
        if ($schedule->status !== ProductionScheduleStatus::SUBMITTED) {
            throw new \RuntimeException('Only submitted schedules can be approved.');
        }
        $schedule->update([
            'status'       => ProductionScheduleStatus::APPROVED,
            'approved_by'  => $approver->id,
            'approved_at'  => now(),
        ]);
    }
}
```

---

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Filament v3 `Forms\Form` schema | Filament v4 `Schemas\Schema` unified layer | v4.0 (Feb 2025) | All form/infolist/table schemas use same class |
| Filament v3 `->form(Form $form)` | `->form(Schema $schema)` | v4.0 | Method signatures changed — existing project uses v4 correctly |
| Filament v3 `getHeaderActions()` | Still `getHeaderActions()` on Pages | unchanged | Pattern carries forward |
| Separate form/infolist schema files | Already extracted to `Schemas/` subfolder | Phase 1 decision | Continue extracting to dedicated schema classes |

**Deprecated/outdated:**
- `Filament\Forms\Form`: Replaced by `Filament\Schemas\Schema` in Filament 4. Do not use.
- Model observers for business logic: Rejected in `[Phase 01-03]` decision — use Action classes.

---

## Environment Availability

Step 2.6: SKIPPED — Phase 4 is purely code/config changes. All dependencies (PHP, Composer, Laravel, Filament) are verified operational. No new external services or CLI tools required. Database schema is already migrated (all 5 production_schedule-related migrations exist). No additional `composer install` or `npm install` required.

---

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.5.3 |
| Config file | `/Users/guidutra/PhpstormProjects/Impex_Main_app/phpunit.xml` |
| Quick run command | `php artisan test --filter=ProductionSchedule` |
| Full suite command | `php artisan test` |

### Phase Requirements to Test Map

Phase 4 requirements are TBD (not yet mapped in REQUIREMENTS.md). Based on the phase description, the following behaviors need test coverage:

| Behavior | Test Type | Automated Command | File Exists? |
|----------|-----------|-------------------|-------------|
| ProductionScheduleComponent model — CRUD persists correctly | Unit | `php artisan test --filter=ProductionScheduleComponentTest` | Wave 0 gap |
| Status transitions: draft → submitted → approved (happy path) | Unit | `php artisan test --filter=ProductionScheduleStatusTest` | Wave 0 gap |
| Status guard: cannot approve a draft (unhappy path) | Unit | same file | Wave 0 gap |
| SubmitProductionScheduleAction throws if not draft | Unit | same file | Wave 0 gap |
| ApproveProductionScheduleAction sets approved_by and approved_at | Unit | same file | Wave 0 gap |
| ComponentsRelationManager: supplier can create component | Feature | `php artisan test --filter=ComponentManagementTest` | Wave 0 gap |
| Tenant isolation: supplier cannot see other supplier's components | Feature | same file | Wave 0 gap |
| Confirm-today action: entries for today get actual_quantity updated | Feature | `php artisan test --filter=ConfirmTodayActionTest` | Wave 0 gap |
| Risk aggregation: component ETA past-due correctly flagged | Unit | `php artisan test --filter=ComponentRiskTest` | Wave 0 gap |

### Sampling Rate

- **Per task commit:** `php artisan test --filter=ProductionSchedule`
- **Per wave merge:** `php artisan test`
- **Phase gate:** Full suite green before `/gsd:verify-work`

### Wave 0 Gaps

- [ ] `tests/Unit/ProductionScheduleStatusTest.php` — covers status transitions, guard logic, enum casts
- [ ] `tests/Unit/ProductionScheduleComponentTest.php` — covers model CRUD, ETA risk flag logic
- [ ] `tests/Feature/ComponentManagementTest.php` — covers portal CRUD + tenant isolation for components
- [ ] `tests/Feature/ConfirmTodayActionTest.php` — covers confirm-today flow end-to-end

Existing test infrastructure: `SupplierProductionUpdateTest.php` covers the permission/tenant pattern. New tests must follow the same `RefreshDatabase` + manual `Permission::firstOrCreate` + Company/User fixture pattern — do NOT rely on seeders.

---

## Open Questions

1. **Calendar vs. timeline view: exact layout preference**
   - What we know: Phase description says "calendar/timeline view" but no mockup exists
   - What's unclear: Is this a 7-day rolling window? A month grid? A Gantt-style horizontal strip per product?
   - Recommendation: Default to the horizontal strip per product (matching the existing `pi-production-progress.blade.php` collapsible detail table) — shows dates as columns, products as rows, spans the production date range. This is the minimum viable "calendar view" without external JS. If the user wants a full calendar month grid later, it can be a separate enhancement.

2. **Notification delivery for approval workflow**
   - What we know: `Filament\Notifications\Notification::make()->send()` works for in-app flash notifications; `resend/resend-laravel` is installed for email.
   - What's unclear: Should approval/rejection trigger email to the supplier? If yes, this requires email notification classes.
   - Recommendation: Implement Filament in-app notifications for Phase 4 (already proven pattern). Defer email notifications to Phase 4 v2 requirements (PORT-01 area) unless explicitly required.

3. **"Submit for approval" button placement — portal list vs. view page**
   - What we know: The portal ListProductionSchedules shows a simple table; ViewProductionSchedule shows the entries. The approval flow requires the supplier to "submit" the schedule.
   - What's unclear: Should "submit" appear on the list row (inline action) or only on the view page?
   - Recommendation: Header action on the view page only, with a status badge visible on the list page. This keeps the list page clean and forces the supplier to review before submitting.

4. **Admin risk aggregation: widget on dashboard vs. dedicated page**
   - What we know: `SupplierOverviewWidget` is a `StatsOverviewWidget` with KPI stats. `OrderPipelineKanban` is a full custom Page. The risk aggregation involves showing which schedules have components with past-due ETAs.
   - What's unclear: Should this be a widget on the admin Production Schedules view, or a standalone risk dashboard?
   - Recommendation: A `StatsOverviewWidget` on the admin ViewProductionSchedule page showing component risk summary (N components late, earliest overdue ETA) is sufficient for Phase 4. A full risk dashboard page can be Phase 4.v2.

---

## Sources

### Primary (HIGH confidence)

- Existing project source files (Filament 4.7.2 confirmed by `composer show`) — verified API patterns from `EntriesRelationManager.php`, `ViewProductionSchedule.php`, `ProductionScheduleInfolist.php`, `SupplierOverviewWidget.php`, `OrderPipelineKanban.php`
- Migration files `2026_03_30_110000` and `2026_03_30_120000` — confirmed exact column names and FK structure for status fields and components table
- `phpunit.xml` — confirmed SQLite in-memory test setup, PHPUnit 11, no Pest

### Secondary (MEDIUM confidence)

- `SupplierProductionUpdateTest.php` — verified test fixture pattern (no seeders, `Permission::firstOrCreate`, `RefreshDatabase`)
- `CLAUDE.md` — project architectural constraints (DDD, Action pattern, file size, no observers)
- `STATE.md` decisions log — confirmed Phase 01-03 decisions that constrain this phase (PI-level readiness, Action pattern, explicit DDD)

### Tertiary (LOW confidence)

- None — all findings are from direct file inspection of the actual codebase

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — versions verified via `composer show`
- Architecture: HIGH — patterns derived directly from existing project code, not hypothetical
- Pitfalls: HIGH — each pitfall is traceable to a specific existing file or documented decision
- Test infrastructure: HIGH — phpunit.xml and existing test files inspected directly

**Research date:** 2026-03-31
**Valid until:** 2026-06-30 (stable stack; Filament 4.x minor updates are backward compatible)
