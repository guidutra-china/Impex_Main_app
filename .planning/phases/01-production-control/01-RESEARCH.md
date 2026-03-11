# Phase 1: Production Control - Research

**Researched:** 2026-03-11
**Domain:** Laravel 12 + Filament 4 — extending existing ProductionSchedule domain with actual production tracking, supplier portal UI, and automatic payment schedule updates
**Confidence:** HIGH (brownfield codebase, full source available, no external library uncertainty)

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|-----------------|
| PROD-01 | Fornecedor pode registrar quantidade produzida diária por item via Supplier Portal | Add `actual_quantity` to `production_schedule_entries`; new SupplierPortal Resource with editable table |
| PROD-02 | Sistema compara planejado vs. realizado com visualização clara (dashboard/tabela) | New admin view page with side-by-side comparison; ViewEntry + custom Blade or Livewire widget |
| PROD-03 | Sistema calcula automaticamente quantidade pronta para embarque baseado em produção realizada | Service method on ProductionSchedule: sum `actual_quantity` by item up to today; display in admin |
| PROD-04 | Sistema gera/atualiza planejamento de pagamento baseado em produção pronta para embarque | New Domain Action `UpdatePaymentScheduleFromProductionAction`; triggered when actuals are saved |
</phase_requirements>

---

## Summary

The codebase already has `ProductionSchedule` and `ProductionScheduleEntry` models with a `quantity` column representing the **planned** quantity per item per date. The core gap for Phase 1 is a single missing column — `actual_quantity` on `production_schedule_entries` — plus the supplier-facing UI to fill it in, the admin-facing comparison view, and the business logic to translate accumulated production actuals into payment schedule updates.

The Supplier Portal panel already exists (`/supplier`, panel ID `supplier-portal`) with tenant-scoping via `Company` and the pattern of `protected static ?string $tenantOwnershipRelationshipName = 'supplierCompany'`. The new `ProductionScheduleResource` in the Supplier Portal should follow the exact same pattern as `PurchaseOrderResource` — read-only header, editable entries via a table with `EditAction` limited to `actual_quantity` only.

For PROD-04, the key insight is that `CalculationBase::AFTER_PRODUCTION` already exists in the `CalculationBase` enum, meaning the payment term system is already aware of production-triggered payments. The `GeneratePaymentScheduleAction` creates items but does not handle production-triggered updates. A new `UpdatePaymentScheduleFromProductionAction` must detect entries with `due_condition = AFTER_PRODUCTION` and update their `due_date` (or trigger a status update) when production readiness reaches the percentage threshold.

**Primary recommendation:** Keep all changes additive — one migration adding `actual_quantity nullable integer`, one new Domain Action, one new SupplierPortal Resource, one enhanced admin View page. No rewrites of existing code.

---

## Standard Stack

### Core (already in project)
| Component | Version | Purpose | Used As |
|-----------|---------|---------|---------|
| Laravel | 12.x | Framework | Eloquent, migrations, actions |
| Filament | 4.x | Admin + portal UI | Resources, RelationManagers, Infolists |
| PHPUnit | 11.x | Testing | Feature tests, SQLite in-memory |
| Spatie activitylog | 4.12 | Audit trail | LogsActivity on models |

### No New Packages Required

All Phase 1 work is achievable with the existing stack. Do NOT add any new Composer dependencies.

---

## Architecture Patterns

### Existing Structure to Extend

```
app/Domain/Planning/
├── Actions/
│   ├── ExecuteShipmentPlanAction.php        (existing)
│   ├── GenerateProductionScheduleTemplate.php (existing)
│   ├── ReconcileShipmentPlanAction.php      (existing)
│   └── UpdatePaymentScheduleFromProductionAction.php  ← NEW
├── Models/
│   ├── ProductionSchedule.php               (existing — add accessor)
│   └── ProductionScheduleEntry.php          (existing — add actual_quantity)

app/Filament/SupplierPortal/Resources/
└── ProductionScheduleResource.php           ← NEW (+ Pages/ subdir)

app/Filament/Resources/ProductionSchedules/
├── Schemas/
│   └── ProductionScheduleInfolist.php       (extend with actual vs planned)
└── RelationManagers/
    └── EntriesRelationManager.php           (extend with actual_quantity column)

database/migrations/
└── 2026_03_11_XXXXXX_add_actual_quantity_to_production_schedule_entries.php  ← NEW
```

### Pattern 1: Additive Migration (PROD-01)

Add `actual_quantity` as a **nullable integer** to `production_schedule_entries`. Null means "not yet reported". Zero is a valid reported value (supplier reported but produced nothing that day — different from no report).

```php
// Source: existing migration pattern in this codebase
Schema::table('production_schedule_entries', function (Blueprint $table) {
    $table->integer('actual_quantity')->nullable()->after('quantity');
});
```

Key: `quantity` stays as-is (planned). `actual_quantity` is the new reported field. This is a non-breaking additive migration.

### Pattern 2: Supplier Portal Resource (PROD-01)

The Supplier Portal scopes data via Filament multi-tenancy. `ProductionSchedule` belongs to `supplier_company_id`. The resource must use `tenantOwnershipRelationshipName = 'supplierCompany'`.

Suppliers must be able to:
1. **List** their production schedules (read-only list)
2. **View** a schedule's planned entries (read-only header)
3. **Update `actual_quantity`** on entries inline — via a table with `EditAction` that exposes only `actual_quantity`

Suppliers must NOT:
- Create or delete schedules (those come from the admin)
- Modify `quantity` (planned), `production_date`, or `proforma_invoice_item_id`

Pattern to follow: `PurchaseOrderResource.php` for the outer resource structure (same `canCreate/canEdit/canDelete = false` at resource level). Entries editing uses a nested `RelationManager` or a custom View page with an editable table.

```php
// Pattern from PurchaseOrderResource — apply to ProductionScheduleResource
protected static ?string $tenantOwnershipRelationshipName = 'supplierCompany';

public static function canAccess(): bool
{
    return auth()->user()?->can('supplier-portal:view-production-schedules') ?? false;
}

public static function canCreate(): bool { return false; }
public static function canEdit($record): bool { return false; }
public static function canDelete($record): bool { return false; }
```

Permission string to register: `'supplier-portal:view-production-schedules'` and `'supplier-portal:update-production-actuals'`.

### Pattern 3: Domain Action — UpdatePaymentScheduleFromProduction (PROD-04)

`CalculationBase::AFTER_PRODUCTION` already exists. Payment schedule items with `due_condition = AFTER_PRODUCTION` need their `due_date` set when production reaches the percentage threshold (e.g., 30% of total quantity is ready).

Trigger point: whenever `actual_quantity` is saved on any entry.

Logic:
1. Load the `ProductionSchedule` → its `ProductionScheduleEntry` collection
2. For each `ProformaInvoiceItem` in the schedule: sum `actual_quantity` to get `total_produced`
3. Compare against `ProformaInvoiceItem.quantity` (PI ordered quantity) to get `production_readiness_percentage`
4. Load `PaymentScheduleItem` records for the PI with `due_condition = AFTER_PRODUCTION` and status `PENDING`
5. If `production_readiness_percentage >= item.percentage`, set `due_date = today()` (production milestone reached)

```php
// New action skeleton — app/Domain/Planning/Actions/UpdatePaymentScheduleFromProductionAction.php
class UpdatePaymentScheduleFromProductionAction
{
    public function execute(ProductionSchedule $schedule): array
    {
        // Returns array of updated PaymentScheduleItem IDs for feedback
    }
}
```

This action is called from the Supplier Portal after saving `actual_quantity` entries, and also available as an admin action on the admin ProductionSchedule view page.

### Pattern 4: Planned vs. Actual Comparison View (PROD-02)

The admin `ViewProductionSchedule` page already has the `EntriesRelationManager`. For PROD-02, the comparison view needs to show per-item, per-date: `planned = quantity`, `actual = actual_quantity`, `delta = actual - planned`, visual indicator (color badge: ahead / on-track / delayed / not-reported).

Options:
1. **Extend `EntriesRelationManager` table** — add `actual_quantity` column with color badge. Simple but flat — no summary row.
2. **New custom Infolist section with `ViewEntry`** — renders a Blade partial with grouped table. More control over layout.
3. **New Livewire widget on `ViewProductionSchedule` page** — full control, separate data load.

**Recommendation:** Extend `EntriesRelationManager` with `actual_quantity` column + `TextColumn::make('actual_quantity')->badge()->color(fn...)` for color coding. Add a summary section to `ProductionScheduleInfolist` with `TextEntry` showing `total_planned`, `total_produced`, `shipment_ready_quantity`. This is the minimal change approach consistent with existing code.

### Pattern 5: Shipment-Ready Quantity Accessor (PROD-03)

Add to `ProductionSchedule` model:

```php
// app/Domain/Planning/Models/ProductionSchedule.php
public function getTotalActualQuantityAttribute(): int
{
    return $this->entries->sum(fn ($e) => $e->actual_quantity ?? 0);
}

public function getShipmentReadyQuantityByItem(): \Illuminate\Support\Collection
{
    // Returns Collection keyed by proforma_invoice_item_id => actual sum
    return $this->entries
        ->groupBy('proforma_invoice_item_id')
        ->map(fn ($entries) => $entries->sum(fn ($e) => $e->actual_quantity ?? 0));
}
```

These are pure in-memory accessors — no new queries if `entries` is eager-loaded.

### Anti-Patterns to Avoid

- **Do NOT add `actual_quantity` to `ProductionSchedule`** (the header model). Actuals belong on entries (per item per date), same level as `quantity`.
- **Do NOT allow suppliers to delete entries** — they should only update `actual_quantity` on entries the admin created from the planned schedule.
- **Do NOT trigger payment updates synchronously on every keystroke** in Filament forms — use an explicit save action or model observer on `ProductionScheduleEntry::updated`.
- **Do NOT use `auth()->id()` in the new Action** — follow the existing pattern concern: pass `?int $actingUserId = null` or accept that this runs in a web context where `auth()->id()` is available. For now, accept the existing pattern (this is a known tech debt, not to be fixed in Phase 1).
- **Do NOT touch `GeneratePaymentScheduleAction`** — it handles initial schedule creation. The new action handles production-triggered updates separately.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Tenant data isolation in Supplier Portal | Custom `where supplier_company_id = ?` on every query | Filament `tenantOwnershipRelationshipName = 'supplierCompany'` | Framework handles scope automatically |
| Edit single field in relation table | Custom Livewire component | `RelationManager` with `EditAction` scoped to `actual_quantity` only | Already proven pattern in `EntriesRelationManager` |
| Color-coded status in table column | Custom HTML rendering | `TextColumn::badge()->color(fn ($state, $record) => ...)` | Filament built-in |
| Audit trail for actual quantity changes | Custom logging table | `LogsActivity` trait already on `ProductionSchedule` — extend to entry model | Already set up in infrastructure |

**Key insight:** The entire UI framework is already configured. Phase 1 is 90% domain logic and migrations, with thin Filament wrappers. Don't over-engineer the presentation layer.

---

## Common Pitfalls

### Pitfall 1: Null vs. Zero for actual_quantity
**What goes wrong:** Treating `actual_quantity = null` (not reported) the same as `actual_quantity = 0` (reported zero production). This causes entries to appear "behind" when they're simply "pending".
**Why it happens:** PHP's falsy comparison treats both null and 0 as false.
**How to avoid:** Always use `$entry->actual_quantity !== null` to check "has been reported". Use `$entry->actual_quantity ?? 0` only when summing (calculating totals, never for status indicators).
**Warning signs:** Comparison view shows all unreported entries as "delayed" on day 1.

### Pitfall 2: Tenant Scope Missing on Supplier Portal Resource
**What goes wrong:** Supplier can see all production schedules across all companies, not just their own.
**Why it happens:** Forgetting `$tenantOwnershipRelationshipName` or using a relationship name that doesn't match the FK column.
**How to avoid:** `ProductionSchedule` has `supplier_company_id` FK → relationship is `supplierCompany()` → set `protected static ?string $tenantOwnershipRelationshipName = 'supplierCompany'`.
**Warning signs:** Supplier logs in and sees schedules from other companies in the list.

### Pitfall 3: Payment Schedule Update Race / Double-Trigger
**What goes wrong:** `UpdatePaymentScheduleFromProductionAction` is triggered multiple times for the same milestone (once per entry saved), creating multiple due date updates or attempting to re-update already-updated items.
**Why it happens:** Observer fires on every `ProductionScheduleEntry` update, even if production readiness didn't cross a threshold.
**How to avoid:** Add idempotency guard — only update `due_date` if it's currently null (not yet set from production) and the threshold is newly crossed.
**Warning signs:** `PaymentScheduleItem.due_date` gets updated every time any entry is saved, even without threshold crossing.

### Pitfall 4: Entries Relation Manager Allows Supplier to Edit Planned Quantity
**What goes wrong:** Supplier edits `quantity` (planned) instead of `actual_quantity`, corrupting the original schedule data.
**Why it happens:** `EditAction` form exposes all fillable fields by default.
**How to avoid:** The Supplier Portal `EditAction` form must only include `actual_quantity` field. Use a separate `RelationManager` class for the portal (not reuse admin's `EntriesRelationManager`).
**Warning signs:** `quantity` column values change after supplier interaction.

### Pitfall 5: SQLite Compatibility in Tests
**What goes wrong:** Tests fail in CI/SQLite because MySQL-specific syntax is used.
**Why it happens:** Tests use SQLite in-memory (configured in `phpunit.xml`). MySQL `FIELD()` in `orderByRaw` doesn't exist in SQLite.
**How to avoid:** Avoid `orderByRaw` with MySQL functions. Use `orderBy('column')` for simple sorting. The existing test suite already runs on SQLite — match that pattern.
**Warning signs:** Tests pass locally (MySQL) but fail in test suite (SQLite).

---

## Code Examples

Verified patterns from existing codebase sources:

### Adding actual_quantity column (migration)
```php
// Additive migration — non-destructive
public function up(): void
{
    Schema::table('production_schedule_entries', function (Blueprint $table) {
        $table->integer('actual_quantity')->nullable()->after('quantity');
    });
}

public function down(): void
{
    Schema::table('production_schedule_entries', function (Blueprint $table) {
        $table->dropColumn('actual_quantity');
    });
}
```

### Supplier Portal Resource — entries inline edit
```php
// In SupplierPortal RelationManager — only expose actual_quantity
public function form(Schema $schema): Schema
{
    return $schema->components([
        TextInput::make('actual_quantity')
            ->label('Quantity Produced')
            ->numeric()
            ->minValue(0)
            ->required(),
        // No quantity, no production_date, no proforma_invoice_item_id
    ]);
}
```

### Color-coded comparison in admin entries table
```php
// Extend EntriesRelationManager table columns
TextColumn::make('actual_quantity')
    ->label('Actual')
    ->numeric()
    ->alignEnd()
    ->placeholder('—')
    ->badge()
    ->color(fn ($state, $record) => match (true) {
        $state === null => 'gray',                  // not reported
        $state >= $record->quantity => 'success',   // on or ahead of plan
        $state > 0 => 'warning',                    // partial
        default => 'danger',                        // reported zero
    }),
```

### UpdatePaymentScheduleFromProductionAction skeleton
```php
// app/Domain/Planning/Actions/UpdatePaymentScheduleFromProductionAction.php
namespace App\Domain\Planning\Actions;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Planning\Models\ProductionSchedule;
use App\Domain\Settings\Enums\CalculationBase;
use Illuminate\Support\Facades\DB;

class UpdatePaymentScheduleFromProductionAction
{
    public function execute(ProductionSchedule $schedule): array
    {
        $schedule->load('entries', 'proformaInvoice.items');
        $updatedItems = [];

        DB::transaction(function () use ($schedule, &$updatedItems) {
            $pi = $schedule->proformaInvoice;
            if (! $pi) return;

            foreach ($pi->items as $piItem) {
                $totalPlanned = $piItem->quantity;
                if ($totalPlanned <= 0) continue;

                $totalProduced = $schedule->entries
                    ->where('proforma_invoice_item_id', $piItem->id)
                    ->sum(fn ($e) => $e->actual_quantity ?? 0);

                $readinessPct = ($totalProduced / $totalPlanned) * 100;

                $scheduleItems = PaymentScheduleItem::where('payable_type', get_class($pi))
                    ->where('payable_id', $pi->id)
                    ->where('due_condition', CalculationBase::AFTER_PRODUCTION)
                    ->where('status', PaymentScheduleStatus::PENDING)
                    ->whereNull('due_date')
                    ->get();

                foreach ($scheduleItems as $item) {
                    if ($readinessPct >= $item->percentage) {
                        $item->update(['due_date' => now()->toDateString()]);
                        $updatedItems[] = $item->id;
                    }
                }
            }
        });

        return $updatedItems;
    }
}
```

---

## State of the Art

| Old Approach | Current Approach | Change Required | Impact |
|--------------|-----------------|-----------------|--------|
| Internal staff updates production data in admin | Supplier updates actuals directly via portal | Add Supplier Portal Resource | Removes intermediary — real-time data |
| `quantity` = only production field on entry | `quantity` (planned) + `actual_quantity` (reported) | Additive migration | Preserves existing schedule data |
| Payment due dates set from shipment dates only | Production milestone also triggers due dates | New action for AFTER_PRODUCTION condition | AFTER_PRODUCTION condition was already modeled, never activated |
| Admin entries table shows only planned quantity | Admin table shows planned + actual + delta with color | Extend RelationManager | No structural change, just UI columns |

**Already modeled but not implemented:**
- `CalculationBase::AFTER_PRODUCTION` — enum case exists, no code acts on it
- `ProductionSchedule::getQuantityReadyByDate()` — method exists on model (uses `quantity`, not `actual_quantity` yet)

---

## Open Questions

1. **Should `actual_quantity` entries be restricted to dates on or before today?**
   - What we know: The production schedule template has future dates (planned batches). Suppliers may want to pre-confirm production.
   - What's unclear: Whether future-dated actuals are a valid business case.
   - Recommendation: Add no date restriction for now. The comparison view will clearly show future vs past. Can be added later as a validation rule.

2. **Should `UpdatePaymentScheduleFromProductionAction` be triggered by a Model Observer or called explicitly?**
   - What we know: The existing pattern avoids observers for side-effect logic (Actions are called explicitly from Filament pages/actions).
   - What's unclear: Whether an observer on `ProductionScheduleEntry` would be acceptable given the existing codebase conventions.
   - Recommendation: Call the action explicitly from the Supplier Portal save handler and from the admin page action button. Avoids observer complexity and aligns with DDD Action pattern.

3. **How should "shipment-ready quantity" (PROD-03) relate to ShipmentPlan quantities?**
   - What we know: ShipmentPlan items have their own `quantity` field representing planned shipment quantities. Production actual doesn't automatically create/update ShipmentPlan items.
   - What's unclear: Whether PROD-03 means "display shipment-ready quantity as a number" or "auto-create ShipmentPlan items".
   - Recommendation: For Phase 1, implement as **display only** — show accumulated `actual_quantity` per PI item as "ready for shipment" in the admin view. ShipmentPlan creation remains manual. This is consistent with the roadmap plans (01-03 is "calculate and display").

---

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.x |
| Config file | `phpunit.xml` (root) |
| Quick run command | `php artisan test --filter ProductionControl` |
| Full suite command | `php artisan test` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|--------------|
| PROD-01 | `actual_quantity` saved on entry via supplier portal | Feature | `php artisan test --filter RecordProductionActualTest` | ❌ Wave 0 |
| PROD-01 | Tenant isolation: supplier only sees their own schedules | Feature | `php artisan test --filter SupplierProductionScheduleScopeTest` | ❌ Wave 0 |
| PROD-02 | Planned vs actual comparison: null = not reported, not zero | Unit | `php artisan test --filter ProductionScheduleActualTest` | ❌ Wave 0 |
| PROD-03 | `shipment_ready_quantity` = sum of actual_quantity per item | Unit | `php artisan test --filter ProductionScheduleActualTest` | ❌ Wave 0 |
| PROD-04 | AFTER_PRODUCTION payment items get due_date when threshold crossed | Feature | `php artisan test --filter UpdatePaymentScheduleFromProductionActionTest` | ❌ Wave 0 |
| PROD-04 | Idempotent: second call does not re-update already-dated items | Feature | `php artisan test --filter UpdatePaymentScheduleFromProductionActionTest` | ❌ Wave 0 |

### Sampling Rate
- **Per task commit:** `php artisan test --filter ProductionControl`
- **Per wave merge:** `php artisan test`
- **Phase gate:** Full suite green before `/gsd:verify-work`

### Wave 0 Gaps
- [ ] `tests/Feature/UpdatePaymentScheduleFromProductionActionTest.php` — covers PROD-04
- [ ] `tests/Feature/RecordProductionActualTest.php` — covers PROD-01 save logic
- [ ] `tests/Unit/ProductionScheduleActualTest.php` — covers PROD-02/PROD-03 accessors

*(Existing test infrastructure — `RefreshDatabase`, SQLite in-memory, factory-less direct `Model::create()` pattern — covers all phase requirements. No framework install needed.)*

---

## Sources

### Primary (HIGH confidence)
- Direct source read: `app/Domain/Planning/Models/ProductionSchedule.php` — existing model, relationships, accessors
- Direct source read: `app/Domain/Planning/Models/ProductionScheduleEntry.php` — existing fields, no `actual_quantity`
- Direct source read: `database/migrations/2026_03_04_100000_create_production_schedule_tables.php` — schema confirmed
- Direct source read: `app/Domain/Settings/Enums/CalculationBase.php` — `AFTER_PRODUCTION` case confirmed to exist
- Direct source read: `app/Domain/Financial/Actions/GeneratePaymentScheduleAction.php` — existing payment logic
- Direct source read: `app/Filament/SupplierPortal/Resources/PurchaseOrderResource.php` — tenant scoping pattern
- Direct source read: `app/Providers/Filament/SupplierPortalPanelProvider.php` — portal configuration
- Direct source read: `phpunit.xml` — SQLite in-memory confirmed

### Secondary (MEDIUM confidence)
- Pattern inference from `EntriesRelationManager.php` — EditAction scoping to subset of fields is standard Filament pattern
- Pattern inference from `GeneratePaymentScheduleActionTest.php` — `Model::create()` without factories is the test pattern in this project

### Tertiary (LOW confidence)
- None — all findings are from direct source reads of the production codebase

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — existing codebase, no new packages
- Architecture: HIGH — all patterns extracted from existing source files
- Pitfalls: HIGH — derived from reading actual code + known concerns documented in CONCERNS.md
- Payment integration: MEDIUM — `AFTER_PRODUCTION` enum case confirmed but its intended trigger logic is inferred, not documented

**Research date:** 2026-03-11
**Valid until:** 2026-04-11 (stable Laravel/Filament version, no API churn risk)
