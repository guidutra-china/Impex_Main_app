# Packing List Redesign — PR #4: Legacy Cleanup

> **For agentic workers:** REQUIRED SUB-SKILL: `superpowers:subagent-driven-development` or `superpowers:executing-plans`.

**Goal:** Drop the legacy `packing_list_items` table and delete all remaining code that references it. After this PR, the cartons model is the sole source of truth for packing list data.

**Context:** PRs #1-#3 built the new cartons model, repointed the PDF + recalc, delivered the Livewire UI, and kept the legacy table alive during the transition via a double-write observer. This PR removes the safety net.

**Preconditions:**
- Full Logistics regression green (`php artisan test --filter=Logistics` → 110+ passed as of end of PR #3).
- No admin UI writes to `packing_list_items` anymore (the RelationManager was deleted in PR #3).
- The `PackingListItemObserver` is inert — nothing in the admin flow touches legacy data.

**Spec reference:** `docs/superpowers/specs/2026-04-08-packing-list-redesign-design.md` §Rollout Plan PR #4.

---

## File Structure

**Created:**
- `database/migrations/2026_04_08_150000_drop_packing_list_items_table.php`

**Deleted:**
- `app/Domain/Logistics/Models/PackingListItem.php`
- `app/Domain/Logistics/Actions/MigratePackingListItemsToCartonsAction.php`
- `app/Domain/Logistics/Actions/GeneratePackingListAction.php` (legacy)
- `app/Domain/Logistics/Actions/SyncCartonsFromLegacyAction.php`
- `app/Domain/Logistics/Observers/PackingListItemObserver.php`
- `app/Console/Commands/MigratePackingListsToCartonsCommand.php`
- `tests/Feature/Logistics/PackingListItemObserverTest.php`
- `tests/Feature/Logistics/MigratePackingListItemsToCartonsActionTest.php`

**Modified:**
- `app/Providers/AppServiceProvider.php` — remove observer registration + import
- `app/Domain/Logistics/Models/Shipment.php` — remove `packingListItems()` HasMany + import
- `app/Domain/Logistics/Models/ShipmentItem.php` — remove `packingListItems()` HasMany + import

**Not touched (intentional):**
- The original legacy migrations (`2026_02_24_*` through `2026_04_08_120000_*`) remain in the migrations directory as historical record. The new drop migration supersedes them on `migrate:fresh`.

---

## Task 1: Create drop migration

**Files:**
- Create: `database/migrations/2026_04_08_150000_drop_packing_list_items_table.php`

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('packing_list_items');
    }

    public function down(): void
    {
        // Recreate the table with the full schema as it stood at end of PR #3
        // (after add_package_label migration). Data is NOT restored — use the
        // git history of PR #1's MigratePackingListItemsToCartonsAction if you
        // need to rebuild from cartons.
        Schema::create('packing_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_item_id')->nullable()->constrained('shipment_items')->nullOnDelete();
            $table->string('container_number', 50)->nullable();
            $table->string('packaging_type', 30)->default('carton');
            $table->integer('pallet_number')->nullable();
            $table->integer('carton_from')->nullable();
            $table->integer('carton_to')->nullable();
            $table->text('description')->nullable();
            $table->string('package_label', 100)->nullable();
            $table->boolean('is_primary_package')->default(true);
            $table->integer('quantity')->default(0);
            $table->integer('qty_per_carton')->default(0);
            $table->integer('total_quantity')->default(0);
            $table->decimal('gross_weight', 10, 3)->nullable();
            $table->decimal('net_weight', 10, 3)->nullable();
            $table->decimal('total_gross_weight', 10, 3)->nullable();
            $table->decimal('total_net_weight', 10, 3)->nullable();
            $table->decimal('length', 8, 2)->nullable();
            $table->decimal('width', 8, 2)->nullable();
            $table->decimal('height', 8, 2)->nullable();
            $table->decimal('volume', 10, 4)->nullable();
            $table->decimal('total_volume', 10, 4)->nullable();
            $table->text('notes')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }
};
```

- [ ] **Step 2: Do NOT run the migration yet** — defer until after code deletion (Tasks 2-5). Running it now would break tests that still reference `PackingListItem` while we're mid-cleanup.

---

## Task 2: Remove observer registration + relationships

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `app/Domain/Logistics/Models/Shipment.php`
- Modify: `app/Domain/Logistics/Models/ShipmentItem.php`

- [ ] **Step 1: AppServiceProvider**

Remove:
- `use App\Domain\Logistics\Models\PackingListItem;`
- `use App\Domain\Logistics\Observers\PackingListItemObserver;`
- `PackingListItem::observe(PackingListItemObserver::class);` line in `boot()`

- [ ] **Step 2: Shipment model**

Remove:
- `packingListItems()` HasMany method
- `use App\Domain\Logistics\Models\PackingListItem;` (if present — check, it may be implicit since it's in the same namespace)

- [ ] **Step 3: ShipmentItem model**

Remove:
- `packingListItems()` HasMany method
- `use App\Domain\Logistics\Models\PackingListItem;` (if present)

---

## Task 3: Delete legacy code files

- [ ] **Step 1: Delete files**

```bash
rm app/Domain/Logistics/Models/PackingListItem.php
rm app/Domain/Logistics/Actions/MigratePackingListItemsToCartonsAction.php
rm app/Domain/Logistics/Actions/GeneratePackingListAction.php
rm app/Domain/Logistics/Actions/SyncCartonsFromLegacyAction.php
rm app/Domain/Logistics/Observers/PackingListItemObserver.php
rm app/Console/Commands/MigratePackingListsToCartonsCommand.php
rmdir app/Domain/Logistics/Observers 2>/dev/null || true
```

- [ ] **Step 2: Delete legacy tests**

```bash
rm tests/Feature/Logistics/PackingListItemObserverTest.php
rm tests/Feature/Logistics/MigratePackingListItemsToCartonsActionTest.php
```

- [ ] **Step 3: Grep check**

```bash
grep -rn "PackingListItem\b\|packingListItems\|GeneratePackingListAction\b\|MigratePackingListItemsToCartonsAction\|SyncCartonsFromLegacyAction\|PackingListItemObserver\|MigratePackingListsToCartonsCommand" app/ tests/ --include="*.php"
```

Expected: **zero hits**. Comments inside non-deleted files that mention these symbols historically are fine (won't cause autoload errors).

**Note:** References in `database/migrations/*.php` are fine — old migrations can keep referencing the old table name in their own up/down methods; they run as isolated PHP scripts. No autoload dependency on the deleted model.

---

## Task 4: Run the drop migration

- [ ] **Step 1: Run migration**

```bash
php artisan migrate
```

Expected output: `2026_04_08_150000_drop_packing_list_items_table ... DONE`

- [ ] **Step 2: Verify table dropped**

```bash
php artisan tinker --execute="echo \Schema::hasTable('packing_list_items') ? 'EXISTS' : 'DROPPED';"
```

Expected: `DROPPED`

---

## Task 5: Regression pass

- [ ] **Step 1: Logistics sweep**

```bash
php artisan test --filter=Logistics
```

Expected: previous tests minus the two deleted legacy tests still green (80+ tests). If any test fails because it referenced `PackingListItem` indirectly, triage:
- If legit (new test that should work without legacy) → fix it.
- If legacy remnant → delete it.

- [ ] **Step 2: Full suite**

```bash
php artisan test
```

Expected: same 2 pre-existing failures (`GeneratePaymentScheduleActionTest`, `ProductionActualsGridTest`) unrelated to packing list.

- [ ] **Step 3: Manual smoke via tinker**

```bash
php artisan tinker --execute="
\$s = \App\Domain\Logistics\Models\Shipment::has('cartons')->first();
if (\$s) {
    echo 'Shipment: ' . \$s->reference . PHP_EOL;
    echo 'Cartons: ' . \$s->cartons()->count() . PHP_EOL;
    \$tpl = new \App\Domain\Infrastructure\Pdf\Templates\PackingListPdfTemplate(\$s);
    \$ref = new ReflectionMethod(\$tpl, 'getDocumentData');
    \$ref->setAccessible(true);
    \$data = \$ref->invoke(\$tpl);
    echo 'PDF totals: ' . \$data['totals']['total_packages'] . ' packages, ' . \$data['totals']['total_equipment_qty'] . ' pcs' . PHP_EOL;
    echo 'SMOKE OK' . PHP_EOL;
}
"
```

Expected: PDF data still generates correctly from cartons alone.

---

## Verification Checklist

- [ ] Drop migration created and run successfully.
- [ ] `packing_list_items` table does not exist in DB.
- [ ] All 8 legacy code files deleted.
- [ ] All 2 legacy test files deleted.
- [ ] No references to any deleted symbol in app/ or tests/.
- [ ] `Shipment` and `ShipmentItem` models no longer reference `PackingListItem`.
- [ ] `AppServiceProvider` no longer registers `PackingListItemObserver`.
- [ ] Logistics regression green (minus the 2 deleted tests).
- [ ] Full suite: same 2 pre-existing failures only.
- [ ] Manual PDF smoke test via tinker succeeds.
- [ ] Old legacy migrations left intact in `database/migrations/`.

---

## Out of Scope (explicit)

- **Data archival** — anyone who needs a snapshot of `packing_list_items` must take it before running this PR.
- **Reverting this PR in production** — `down()` recreates the schema but not data. Real rollback requires manual data export from a pre-PR #4 backup.
- **Commits** — user handles at end of session.
