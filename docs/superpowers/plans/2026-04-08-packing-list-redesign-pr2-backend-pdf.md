# Packing List Redesign — PR #2: Backend Actions + PDF V2

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the backend primitives that operate on the new `cartons` / `carton_contents` tables introduced by PR #1, rework `PackingListPdfTemplate` to consume cartons directly, and keep the legacy UI working via a double-write observer from `PackingListItem` → cartons. **UI remains the legacy `PackingListRelationManager`** — Livewire replacement is PR #3.

**Architecture:**
- `PackingProgressService` is the single source of truth for counting pieces packed per `ShipmentItem`, correctly deduping multi-box sets via `multi_box_set_id`.
- A suite of thin Domain Actions (`CreateCartonAction`, `AddContentToCartonAction`, `UpdateCartonAction`, `DeleteCartonAction`, `DeleteCartonContentAction`) provides the write surface for future callers (PR #3 Livewire, tests, seeders).
- `RecalculateShipmentTotalsAction` is repointed from `packing_list_items` to `cartons`.
- `PackingListPdfTemplate` is rewritten to build lines from `cartons.contents` instead of merged legacy items. The Blade view `pdf.packing-list.blade.php` stays unchanged — only the data shape shifts, and we preserve its existing keys.
- A `PackingListItemObserver` listens on `saved`/`deleted` of the legacy model and re-syncs cartons for the affected shipment by wiping and re-running `MigratePackingListItemsToCartonsAction`. This keeps both tables consistent while the legacy UI remains the operator surface.
- **`SplitProductAcrossCartonsAction` is intentionally deferred to PR #3** — it requires Livewire component state to hold pending parts between user clicks, which doesn't exist in PR #2.

**Tech stack:** Laravel 11, PHPUnit with `RefreshDatabase`, SQLite in-memory for tests, Spatie ULID via `Illuminate\Support\Str::ulid()`, Pest-compatible (project uses PHPUnit syntax).

**Spec reference:** `docs/superpowers/specs/2026-04-08-packing-list-redesign-design.md` (Rollout Plan, PR #2 row, lines 305–312).

**Preconditions:**
- PR #1 code must be present (cartons tables migrated, `Carton` / `CartonContent` models exist, `MigratePackingListItemsToCartonsAction` exists). Currently these files are untracked in the working tree but migrations have already been run — treat them as in scope for this session.
- The "previous incremental patch" (`package_label` / `is_primary_package` fields on `packing_list_items`, plus related UI/PDF tweaks) is in the working tree uncommitted. This PR ignores it — do **not** touch those diffs. PR #3 will remove them.

---

## File Structure

**Created:**
- `app/Domain/Logistics/Services/PackingProgressService.php`
- `app/Domain/Logistics/DTOs/ShipmentItemProgress.php`
- `app/Domain/Logistics/Enums/PackingProgressStatus.php`
- `app/Domain/Logistics/Actions/CreateCartonAction.php`
- `app/Domain/Logistics/Actions/AddContentToCartonAction.php`
- `app/Domain/Logistics/Actions/UpdateCartonAction.php`
- `app/Domain/Logistics/Actions/DeleteCartonAction.php`
- `app/Domain/Logistics/Actions/DeleteCartonContentAction.php`
- `app/Domain/Logistics/Actions/SyncCartonsFromLegacyAction.php` — observer helper, wipe + re-run migration
- `app/Domain/Logistics/Observers/PackingListItemObserver.php`
- `tests/Unit/Logistics/PackingProgressServiceTest.php`
- `tests/Unit/Logistics/CartonLabelGeneratorTest.php`
- `tests/Feature/Logistics/CreateCartonActionTest.php`
- `tests/Feature/Logistics/AddContentToCartonActionTest.php`
- `tests/Feature/Logistics/UpdateCartonActionTest.php`
- `tests/Feature/Logistics/DeleteCartonActionTest.php`
- `tests/Feature/Logistics/DeleteCartonContentActionTest.php`
- `tests/Feature/Logistics/PackingListPdfV2Test.php`
- `tests/Feature/Logistics/PackingListItemObserverTest.php`
- `tests/Feature/Logistics/RecalculateShipmentTotalsFromCartonsTest.php`

**Modified:**
- `app/Domain/Logistics/Models/Carton.php` — add `HasFactory` pointer is already there; add `packing_list_item_id` reverse link **only if needed by observer** (evaluated during Task 8). Add `contents_count_set_aware` helper scope if useful. Mostly untouched.
- `app/Domain/Logistics/Models/CartonContent.php` — add `packingProgressKey()` helper.
- `app/Domain/Logistics/Actions/RecalculateShipmentTotalsAction.php` — repoint at cartons, fallback chain preserved.
- `app/Domain/Infrastructure/Pdf/Templates/PackingListPdfTemplate.php` — full rewrite of `getDocumentData()` / `buildContainerGroups()`; delete `buildMergedLines()` + `calculateDedupedTotals()` helpers; replace with `buildLinesFromCartons()` and `computeTotalsFromCartons()`.
- `app/Providers/AppServiceProvider.php` (or `EventServiceProvider` — check what the project uses) — register `PackingListItemObserver`.

**Not touched in this PR (intentional):**
- `PackingListRelationManager.php` — UI stays legacy, observer handles sync. PR #3 replaces it.
- `PackingListPdfTemplate.php` — wait, this IS modified here. (Correcting: the template is in scope for this PR.)
- `resources/views/pdf/packing-list.blade.php` — view stays unchanged; we preserve all keys it consumes.
- `GeneratePackingListAction.php` — still targets legacy table; legacy UI invokes it; PR #4 removes.
- `SplitProductAcrossCartonsAction.php` — deferred to PR #3.

---

## Task 1: `PackingProgressService` + supporting types

**Files:**
- Create: `app/Domain/Logistics/Enums/PackingProgressStatus.php`
- Create: `app/Domain/Logistics/DTOs/ShipmentItemProgress.php`
- Create: `app/Domain/Logistics/Services/PackingProgressService.php`
- Create: `tests/Unit/Logistics/PackingProgressServiceTest.php`

- [ ] **Step 1: Create the status enum**

```php
<?php

namespace App\Domain\Logistics\Enums;

enum PackingProgressStatus: string
{
    case NOT_STARTED = 'not_started'; // packed_complete == 0
    case IN_PROGRESS = 'in_progress'; // any multi-box set has unallocated parts
    case PARTIAL     = 'partial';     // some pieces packed, no pending parts
    case COMPLETE    = 'complete';    // packed_complete >= total
}
```

- [ ] **Step 2: Create the DTO**

```php
<?php

namespace App\Domain\Logistics\DTOs;

use App\Domain\Logistics\Enums\PackingProgressStatus;

final readonly class ShipmentItemProgress
{
    /**
     * @param  array<int, array{set_id: string, part_label: ?string}>  $pendingParts
     */
    public function __construct(
        public int $shipmentItemId,
        public int $total,
        public int $packedComplete,
        public int $packedInProgress,
        public array $pendingParts,
        public PackingProgressStatus $status,
    ) {}

    public function remaining(): int
    {
        return max(0, $this->total - $this->packedComplete);
    }
}
```

- [ ] **Step 3: Create `PackingProgressService`**

Contract: `forShipment(Shipment $shipment): Collection<int, ShipmentItemProgress>` keyed by `shipment_item_id`. Also expose `forShipmentItem(ShipmentItem $item): ShipmentItemProgress` for single-item callers (addContent validator).

Counting rules (from spec §"Counting rules (canonical)"):

- Load cartons+contents: `$shipment->loadMissing('cartons.contents')`.
- For each `ShipmentItem` in the shipment:
  - Gather all contents where `shipment_item_id == item.id`.
  - **Standalone contents** (where `multi_box_set_id IS NULL`): `packedComplete += SUM(pieces)`.
  - **Set contents** (where `multi_box_set_id != NULL`): group by `multi_box_set_id`.
    - Consider a set "complete" if it has at least one content row (primary or part) **AND** the backend has no notion of "pending parts" in PR #2 (Split is deferred). Treat every set as complete for now and add `packedComplete += group.first().pieces` (all siblings share the same `pieces` per spec).
    - Pending parts (`IN_PROGRESS` status) only applies in PR #3 when Split exists — in PR #2 the service always returns `pendingParts = []`.
  - Total: `$item->quantity`.
  - Status derivation:
    - `packedComplete == 0` → `NOT_STARTED`
    - `0 < packedComplete < total` → `PARTIAL`
    - `packedComplete >= total` → `COMPLETE`
    - `IN_PROGRESS` unused in PR #2.

- [ ] **Step 4: Test scenarios** (in `PackingProgressServiceTest.php`)

Use `RefreshDatabase`. Build shipments via factories / direct `create()` (no reliance on `ShipmentItem` factory that may not exist — inline `create()` is fine).

Scenario coverage:
1. **Empty shipment** — no cartons, service returns progress with `packedComplete == 0` per item, status `NOT_STARTED`.
2. **Standalone single carton** — item qty 10, one carton with one content `pieces = 10` and `multi_box_set_id = null` → `packedComplete = 10`, status `COMPLETE`.
3. **Standalone mixed carton** — item qty 5, one carton with two contents (item A: 3 pieces, item B: 2 pieces) → each item's progress counted separately.
4. **Standalone partial** — item qty 10, two cartons with 3+4 pieces → `packedComplete = 7`, status `PARTIAL`.
5. **Multi-box set complete** — item qty 1, two cartons each with one content for the same item, shared `multi_box_set_id` ULID, both contents have `pieces = 1` → `packedComplete = 1`, status `COMPLETE` (not 2 — dedup).
6. **Multi-box sets mixed with standalone** — item qty 3: one set of 2 cartons (unit 1 via ULID A, pieces=1), one standalone carton pieces=2 → `packedComplete = 3`, status `COMPLETE`.
7. **Content with null `shipment_item_id`** — should be ignored in counting (orphan).
8. **Multiple items in shipment** — service returns a collection keyed by `shipment_item_id` with independent totals.
9. **Item with no packing data** — returns DTO with `packedComplete = 0`, status `NOT_STARTED`.

- [ ] **Step 5: Run tests**

Run: `php artisan test --filter=PackingProgressServiceTest`
All tests must pass before moving on. This service is consumed by every subsequent task.

- [ ] **Step 6: Commit (deferred — user will commit later)**

---

## Task 2: `CreateCartonAction` + label generator

**Files:**
- Create: `app/Domain/Logistics/Actions/CreateCartonAction.php`
- Create: `tests/Feature/Logistics/CreateCartonActionTest.php`
- Create: `tests/Unit/Logistics/CartonLabelGeneratorTest.php`

- [ ] **Step 1: Implement the action**

```php
<?php

namespace App\Domain\Logistics\Actions;

use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Models\Shipment;
use Illuminate\Support\Facades\DB;

class CreateCartonAction
{
    /**
     * Create a new carton with an auto-generated BOX-NNN label.
     *
     * $defaults may override container_number, pallet_number, packaging_type,
     * weights, dimensions, notes, sort_order. The label is always generated
     * server-side.
     */
    public function execute(Shipment $shipment, array $defaults = []): Carton
    {
        return DB::transaction(function () use ($shipment, $defaults) {
            $label = $this->generateNextLabel($shipment);

            return Carton::create(array_merge([
                'shipment_id'    => $shipment->id,
                'packaging_type' => 'CARTON',
                'sort_order'     => $this->nextSortOrder($shipment),
            ], $defaults, [
                'label' => $label, // always server-generated
            ]));
        });
    }

    private function generateNextLabel(Shipment $shipment): string
    {
        $labels = $shipment->cartons()->pluck('label');

        $max = 0;
        foreach ($labels as $label) {
            if (preg_match('/^BOX-(\d+)$/', $label, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return 'BOX-' . str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

    private function nextSortOrder(Shipment $shipment): int
    {
        return (int) ($shipment->cartons()->max('sort_order') ?? 0) + 1;
    }
}
```

- [ ] **Step 2: Unit test the label generator** (`CartonLabelGeneratorTest.php`)

Use `RefreshDatabase`. Scenarios:
1. Empty shipment → first carton gets `BOX-001`.
2. Shipment with `BOX-001`, `BOX-002` → next is `BOX-003`.
3. Shipment with `BOX-001`, `BOX-005` (gaps allowed) → next is `BOX-006` (max + 1, not gap fill).
4. Shipment with a renamed carton `WOOD-CRATE-A` + `BOX-002` → next is `BOX-003` (non-matching labels are ignored).
5. Multi-shipment isolation — creating cartons in shipment A doesn't affect shipment B's next label.

- [ ] **Step 3: Feature test the action** (`CreateCartonActionTest.php`)

Scenarios:
1. Action creates a carton with auto-generated label and persists it.
2. Defaults are applied (container_number, pallet_number, etc.) but `label` in `$defaults` is ignored (always server-generated).
3. `sort_order` auto-increments.
4. Returns a `Carton` instance with `id` populated.

- [ ] **Step 4: Run tests**

Run: `php artisan test --filter="CartonLabelGeneratorTest|CreateCartonActionTest"`

---

## Task 3: `AddContentToCartonAction`

**Files:**
- Create: `app/Domain/Logistics/Actions/AddContentToCartonAction.php`
- Create: `tests/Feature/Logistics/AddContentToCartonActionTest.php`

- [ ] **Step 1: Implement the action**

```php
<?php

namespace App\Domain\Logistics\Actions;

use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Models\CartonContent;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\Logistics\Services\PackingProgressService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AddContentToCartonAction
{
    public function __construct(
        private readonly PackingProgressService $progress,
        private readonly RecalculateShipmentTotalsAction $recalc,
    ) {}

    public function execute(
        Carton $carton,
        ShipmentItem $item,
        int $pieces,
        ?string $partLabel = null,
        ?string $multiBoxSetId = null,
    ): CartonContent {
        if ($pieces <= 0) {
            throw new InvalidArgumentException('pieces must be > 0');
        }

        if ($carton->shipment_id !== $item->shipment_id) {
            throw new InvalidArgumentException('Carton and ShipmentItem belong to different shipments');
        }

        return DB::transaction(function () use ($carton, $item, $pieces, $partLabel, $multiBoxSetId) {
            $progress = $this->progress->forShipmentItem($item);

            if ($pieces > $progress->remaining()) {
                throw new InvalidArgumentException(sprintf(
                    'Cannot add %d pieces — only %d remaining for shipment item %d',
                    $pieces,
                    $progress->remaining(),
                    $item->id,
                ));
            }

            $content = CartonContent::create([
                'carton_id'        => $carton->id,
                'shipment_item_id' => $item->id,
                'pieces'           => $pieces,
                'part_label'       => $partLabel,
                'multi_box_set_id' => $multiBoxSetId,
                'sort_order'       => ($carton->contents()->max('sort_order') ?? 0) + 1,
            ]);

            $this->recalc->execute($carton->shipment);

            return $content;
        });
    }
}
```

**Note on remaining calculation with multi-box sets:** `PackingProgressService::forShipmentItem()` returns `remaining = total - packedComplete`. For multi-box sets, `packedComplete` already dedupes by `multi_box_set_id`. When adding a content to an existing set (same `multiBoxSetId`), the caller must be aware that subsequent parts of the same set don't reduce `remaining` further — this is correct because the unit is counted once. A re-check: if caller passes `multiBoxSetId` that already exists for this item, the validation `$pieces > $progress->remaining()` may fail spuriously. **Resolution**: before validating, check if `multiBoxSetId` is non-null AND already present in existing contents for this item → if yes, skip the `remaining` check (adding to existing set). Add this branch to the action.

- [ ] **Step 2: Feature test scenarios**

1. Happy path: standalone content, `remaining` decrements, recalc fires.
2. `pieces <= 0` throws.
3. Carton and ShipmentItem in different shipments throws.
4. `pieces > remaining` throws.
5. Exactly equal to `remaining` succeeds and pushes item to `COMPLETE`.
6. Multi-box set: first content for set consumes 1 "unit" from `remaining`; second content with same `multi_box_set_id` does **not** further reduce remaining (set already counted).
7. Mixed carton: adding multiple contents to the same carton for different items — each validated independently.
8. Recalc dispatched exactly once per successful add.

- [ ] **Step 3: Run tests**

Run: `php artisan test --filter=AddContentToCartonActionTest`

---

## Task 4: `UpdateCartonAction`

**Files:**
- Create: `app/Domain/Logistics/Actions/UpdateCartonAction.php`
- Create: `tests/Feature/Logistics/UpdateCartonActionTest.php`

- [ ] **Step 1: Implement the action**

```php
<?php

namespace App\Domain\Logistics\Actions;

use App\Domain\Logistics\Models\Carton;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class UpdateCartonAction
{
    public function __construct(
        private readonly RecalculateShipmentTotalsAction $recalc,
    ) {}

    /**
     * Update mutable carton fields. Rejects changes to `shipment_id`.
     * Label changes are allowed but the caller is expected to confirm with the user
     * (UI concern — not enforced here).
     *
     * If the resulting carton has contents with weight_share set, validates that
     * SUM(weight_share) == gross_weight within a 0.001 kg tolerance.
     */
    public function execute(Carton $carton, array $attributes): Carton
    {
        unset($attributes['id'], $attributes['shipment_id']);

        return DB::transaction(function () use ($carton, $attributes) {
            $carton->fill($attributes)->save();

            $this->validateWeightShareConsistency($carton);

            $this->recalc->execute($carton->shipment);

            return $carton->fresh('contents');
        });
    }

    private function validateWeightShareConsistency(Carton $carton): void
    {
        $contents = $carton->contents()->get();
        $withShare = $contents->whereNotNull('weight_share');

        if ($withShare->isEmpty()) {
            return; // no per-content weight breakdown
        }

        if ($withShare->count() !== $contents->count()) {
            throw new InvalidArgumentException(
                'Weight share must be set on all carton contents or none (all-or-nothing).'
            );
        }

        if ($carton->gross_weight === null) {
            throw new InvalidArgumentException(
                'Cannot validate weight share: carton.gross_weight is null.'
            );
        }

        $sum = (float) $withShare->sum(fn ($c) => (float) $c->weight_share);
        $gross = (float) $carton->gross_weight;

        if (abs($sum - $gross) > 0.001) {
            throw new InvalidArgumentException(sprintf(
                'Sum of weight_share (%.3f) does not equal carton.gross_weight (%.3f).',
                $sum,
                $gross,
            ));
        }
    }
}
```

- [ ] **Step 2: Feature test scenarios**

1. Update box-level fields (container_number, pallet_number, dimensions) — persist and recalc.
2. `shipment_id` in attributes is silently ignored.
3. No weight_share set → validation skipped.
4. All weight_share set and sum matches `gross_weight` → success.
5. All weight_share set but sum != gross_weight → throws.
6. Mixed state (some contents have weight_share, others don't) → throws.
7. weight_share set but `gross_weight` null → throws.
8. Tolerance: sum = 10.0005, gross_weight = 10.000 → accepted (diff < 0.001).

- [ ] **Step 3: Run tests**

Run: `php artisan test --filter=UpdateCartonActionTest`

---

## Task 5: `DeleteCartonAction` + `DeleteCartonContentAction`

**Files:**
- Create: `app/Domain/Logistics/Actions/DeleteCartonAction.php`
- Create: `app/Domain/Logistics/Actions/DeleteCartonContentAction.php`
- Create: `tests/Feature/Logistics/DeleteCartonActionTest.php`
- Create: `tests/Feature/Logistics/DeleteCartonContentActionTest.php`

- [ ] **Step 1: Implement both actions**

`DeleteCartonAction`:

```php
public function execute(Carton $carton): void
{
    $shipment = $carton->shipment;

    DB::transaction(function () use ($carton) {
        $carton->contents()->delete(); // explicit, even with cascade, to fire observers
        $carton->delete();
    });

    $this->recalc->execute($shipment);
}
```

`DeleteCartonContentAction`:

```php
public function execute(CartonContent $content): void
{
    $shipment = $content->carton->shipment;

    $content->delete();

    $this->recalc->execute($shipment);
}
```

- [ ] **Step 2: Test scenarios**

`DeleteCartonActionTest`:
1. Delete carton — cascade removes contents.
2. Shipment totals recalculated.
3. Deleting the only carton leaves the shipment with zero cartons; recalc falls back to shipment_items totals (PR #1 behavior preserved).
4. Deleting does not affect other cartons in the same shipment.

`DeleteCartonContentActionTest`:
1. Delete one content from a multi-content carton — the other contents survive.
2. Shipment totals recalculated.
3. Deleting frees pieces in `PackingProgressService` — `remaining` increases by the deleted `pieces`.
4. Deleting a member of a multi-box set: if other members still exist, the set is still counted (per spec counting rules). If it was the last member, the set disappears and `packedComplete` decrements.

- [ ] **Step 3: Run tests**

Run: `php artisan test --filter="DeleteCartonActionTest|DeleteCartonContentActionTest"`

---

## Task 6: `RecalculateShipmentTotalsAction` — repoint at cartons

**Files:**
- Modify: `app/Domain/Logistics/Actions/RecalculateShipmentTotalsAction.php`
- Create: `tests/Feature/Logistics/RecalculateShipmentTotalsFromCartonsTest.php`

- [ ] **Step 1: Update the action**

Replace the `packingListItems` aggregation with a `cartons` aggregation:

```php
public function execute(Shipment $shipment): void
{
    $this->syncCurrencyCode($shipment);

    $totals = $shipment->cartons()
        ->selectRaw('
            COUNT(*) as total_packages,
            COALESCE(SUM(gross_weight), 0) as total_gross,
            COALESCE(SUM(net_weight), 0) as total_net,
            COALESCE(SUM(volume), 0) as total_vol
        ')
        ->first();

    if ($totals && (int) $totals->total_packages > 0) {
        $shipment->update([
            'total_packages'     => (int) $totals->total_packages,
            'total_gross_weight' => $totals->total_gross,
            'total_net_weight'   => $totals->total_net,
            'total_volume'       => $totals->total_vol,
        ]);

        return;
    }

    // Fallback chain: cartons empty → shipment_items totals (unchanged behavior).
    $itemTotals = $shipment->items()
        ->selectRaw('SUM(total_weight) as total_weight, SUM(total_volume) as total_volume')
        ->first();

    $shipment->update([
        'total_gross_weight' => $itemTotals->total_weight,
        'total_net_weight'   => null,
        'total_volume'       => $itemTotals->total_volume,
        'total_packages'     => null,
    ]);
}
```

`syncCurrencyCode()` stays as-is.

- [ ] **Step 2: Test scenarios**

1. Shipment with 3 cartons → `total_packages = 3`, weights summed.
2. Shipment with 0 cartons but populated shipment_items → falls back to item totals.
3. Shipment with 0 cartons and 0 items → totals nulled.
4. Cartons with null weights handled (COALESCE to 0).
5. Currency code sync preserved (doesn't regress).

- [ ] **Step 3: Run tests**

Run: `php artisan test --filter=RecalculateShipmentTotalsFromCartonsTest`

**Regression check:** Also run existing tests that depend on `RecalculateShipmentTotalsAction`:

Run: `php artisan test --filter=Shipment`

If legacy tests fail because they rely on the packingListItems aggregation path, update the failing tests to seed cartons instead (or add cartons alongside packingListItems so both paths work during the transition). Do NOT add conditional branches back to the action — the repoint is the whole point of this task.

---

## Task 7: `PackingListPdfTemplate` — consume cartons

**Files:**
- Modify: `app/Domain/Infrastructure/Pdf/Templates/PackingListPdfTemplate.php`
- Create: `tests/Feature/Logistics/PackingListPdfV2Test.php`

- [ ] **Step 1: Rewrite `getDocumentData()` to load cartons**

Change the eager load and data source:

```php
protected function getDocumentData(): array
{
    /** @var Shipment $shipment */
    $shipment = $this->model;
    $shipment->loadMissing([
        'company',
        'companyBranch',
        'items.proformaInvoiceItem.product.companies',
        'items.proformaInvoiceItem.proformaInvoice',
        'cartons.contents.shipmentItem.proformaInvoiceItem.product.companies',
    ]);

    $this->clientCompanyId = $shipment->company_id;
    $this->warmPivotCacheFromCartons($shipment);

    // ... rest of header data unchanged ...

    $containerGroups = $this->buildContainerGroupsFromCartons($shipment);
    $totals          = $this->computeTotalsFromCartons($shipment);

    // ... return unchanged keys: shipment, client, container_groups, has_multiple_containers, totals, import_modality ...
}
```

- [ ] **Step 2: Implement `buildContainerGroupsFromCartons()`**

Logic:
1. Group cartons by `container_number` (null → `__none__`).
2. For each group, sort cartons by `sort_order` then `label`.
3. For each carton, emit one or more lines:
   - If the carton has 1 content → single line with full info (`equipment_qty = content.pieces`, `package_qty = 1`).
   - If the carton has N contents → first content becomes the main line (carries `package_qty = 1` and box-level fields); subsequent contents become sub-item lines with blank `package_qty`, blank weight/dim (matching legacy "mixed carton" visual).
4. For multi-box sets: render a `[#xxxx]` badge on the part_label field for visual continuity (optional, minor polish).
5. Preserve the existing line keys exactly: `package_no`, `pallet`, `model_no`, `product_name`, `description`, `unit`, `equipment_qty`, `package_qty`, `net_weight`, `gross_weight`, `dimensions`, `volume`, `is_sub_item`.
6. `package_no` ← `carton.label` (e.g. `BOX-001`). This is a visible change vs. the legacy `1-10` range format; accept it as the new format.
7. Per-container totals (`container_groups[$i]['totals']`): use `computeTotalsForCartons($cartons)` helper that operates on the subset.

- [ ] **Step 3: Implement `computeTotalsFromCartons(Shipment $shipment): array`**

```php
private function computeTotalsFromCartons(Shipment $shipment): array
{
    $cartons = $shipment->cartons;

    $totalPackages = $cartons->count();
    $totalGross    = (float) $cartons->sum(fn ($c) => (float) $c->gross_weight);
    $totalNet      = (float) $cartons->sum(fn ($c) => (float) $c->net_weight);
    $totalVolume   = (float) $cartons->sum(fn ($c) => (float) $c->volume);

    // Equipment qty uses PackingProgressService to dedup multi-box sets.
    $progress = app(PackingProgressService::class)->forShipment($shipment);
    $totalEquipmentQty = (int) $progress->sum(fn ($p) => $p->packedComplete);

    return [
        'total_packages'     => $totalPackages,
        'total_gross_weight' => $totalGross,
        'total_net_weight'   => $totalNet,
        'total_volume'       => $totalVolume,
        'total_equipment_qty'=> $totalEquipmentQty,
        'packages'           => $totalPackages,
        'equipment_qty'      => $totalEquipmentQty,
        'gross_weight'       => $totalGross,
        'net_weight'         => $totalNet,
        'volume'             => $totalVolume,
    ];
}
```

A sibling `computeTotalsForCartons(Collection $cartons): array` (no service call, no dedupe) is used for **per-container subtotals** — dedupe at the shipment level is enough for the grand total; per-container subtotals can sum `pieces` directly.

- [ ] **Step 4: Delete legacy helpers**

Remove from `PackingListPdfTemplate.php`:
- `buildMergedLines()`
- `calculateDedupedTotals()`
- `formatPackageNumber()` (replaced by `carton.label` passthrough)
- The `packingListItems` portion of `warmPivotCache()` — replace with a `warmPivotCacheFromCartons()` that walks `$shipment->cartons->flatMap->contents`.

- [ ] **Step 5: Feature test (`PackingListPdfV2Test.php`)**

Use `RefreshDatabase`. For each of the 4 canonical scenarios, build a shipment with cartons + contents and assert `getDocumentData()` output shape:

**Scenario 1 — Normal (10 boxes, 10 pcs each of Sandals):**
- 10 cartons, each with one content (pieces=10, shipment_item_id=sandals), null `multi_box_set_id`.
- Expected totals: `total_packages=10`, `total_equipment_qty=100`.
- Expected lines: 10, all main, no sub-items.

**Scenario 2 — Overflow (9 full + 1 mixed with 5 sandals + 3 socks):**
- 9 cartons with 10 sandals each, 1 carton with 2 contents (5 sandals, 3 socks).
- Expected: `total_packages=10`, `total_equipment_qty=98` (95 sandals + 3 socks; assume socks total is 3).
- The mixed carton emits 2 lines (first main, second sub-item).

**Scenario 3 — Multi-box product (1 unit, 2 boxes, Frame + Accessories):**
- Item `Machine X` qty 1. Two cartons, each with one content, both sharing the same `multi_box_set_id`, `part_label` = 'Frame' and 'Accessories'.
- Expected: `total_packages=2`, `total_equipment_qty=1` (dedupe via progress service).
- Each carton emits one line, but `equipment_qty` display on each line can be the per-content `pieces` (=1 each); the grand total uses the deduped number.

**Scenario 4 — Multi-box + sharing (Accessories box also holds sandals + socks):**
- Carton BOX-001: one content (Machine X Frame, set A).
- Carton BOX-002: three contents (Machine X Accessories set A, Sandals pieces=5, Socks pieces=3).
- Expected: `total_packages=2`, `total_equipment_qty=1+5+3=9`.
- BOX-002 emits 3 lines, first main, two sub-items.

Assert keys, counts, totals. Full PDF rendering is out of scope — assert the data shape is correct and the view's `@foreach($container_groups)` / `@foreach($group['lines'])` will iterate as expected.

- [ ] **Step 6: Smoke test — generate a real PDF**

Run: `php artisan tinker --execute="\$s = \App\Domain\Logistics\Models\Shipment::has('cartons')->first(); if (\$s) { dd((new \App\Domain\Infrastructure\Pdf\Templates\PackingListPdfTemplate(\$s))->getDocumentData()); }"`

This catches runtime errors (missing keys, null accessors) that unit tests might miss.

---

## Task 8: `PackingListItemObserver` — legacy → cartons double-write

**Files:**
- Create: `app/Domain/Logistics/Actions/SyncCartonsFromLegacyAction.php`
- Create: `app/Domain/Logistics/Observers/PackingListItemObserver.php`
- Modify: `app/Providers/AppServiceProvider.php` (register observer)
- Create: `tests/Feature/Logistics/PackingListItemObserverTest.php`

- [ ] **Step 1: Implement `SyncCartonsFromLegacyAction`**

```php
<?php

namespace App\Domain\Logistics\Actions;

use App\Domain\Logistics\Models\Shipment;
use Illuminate\Support\Facades\DB;

class SyncCartonsFromLegacyAction
{
    public function __construct(
        private readonly MigratePackingListItemsToCartonsAction $migrate,
    ) {}

    /**
     * Wipe cartons for this shipment and rebuild from current packing_list_items.
     * Used by PackingListItemObserver to keep the new table in sync while the
     * legacy UI is still operator-facing (PR #2 transition).
     */
    public function execute(Shipment $shipment): void
    {
        DB::transaction(function () use ($shipment) {
            $shipment->cartons()->each(function ($carton) {
                $carton->contents()->delete();
                $carton->delete();
            });

            $this->migrate->execute($shipment);
        });
    }
}
```

- [ ] **Step 2: Implement the observer**

```php
<?php

namespace App\Domain\Logistics\Observers;

use App\Domain\Logistics\Actions\SyncCartonsFromLegacyAction;
use App\Domain\Logistics\Models\PackingListItem;

class PackingListItemObserver
{
    public function __construct(
        private readonly SyncCartonsFromLegacyAction $sync,
    ) {}

    public function saved(PackingListItem $item): void
    {
        if ($item->shipment) {
            $this->sync->execute($item->shipment);
        }
    }

    public function deleted(PackingListItem $item): void
    {
        if ($item->shipment) {
            $this->sync->execute($item->shipment);
        }
    }
}
```

Notes:
- `saved` covers both `created` and `updated` events.
- We deliberately re-sync the entire shipment on any change. This is O(n) per save where n is the number of cartons, but shipments typically have 10–100 cartons — acceptable for the PR #2 transition window. Perf optimization deferred until observed as a problem.
- The observer must **not** be triggered by its own writes. Since cartons are a separate table, writing to cartons inside `SyncCartonsFromLegacyAction` doesn't re-enter the `PackingListItem` observer — safe.

- [ ] **Step 3: Register the observer**

Find the project's observer registration convention (`AppServiceProvider::boot()` or an `EventServiceProvider`) and add:

```php
PackingListItem::observe(PackingListItemObserver::class);
```

If the project uses the `#[ObservedBy]` attribute (Laravel 11+), prefer that — add the attribute to `PackingListItem.php` directly:

```php
#[\Illuminate\Database\Eloquent\Attributes\ObservedBy([\App\Domain\Logistics\Observers\PackingListItemObserver::class])]
class PackingListItem extends Model
```

- [ ] **Step 4: Test scenarios**

1. **Create legacy item** → carton(s) appear in `cartons` table matching the migration logic.
2. **Update legacy item** (e.g., change gross_weight) → cartons rebuilt, new weight reflected.
3. **Delete legacy item** → corresponding cartons disappear. Other items' cartons remain.
4. **Legacy item with `is_primary_package = false` + primary sibling** → cartons created with matching `multi_box_set_id` between siblings (verifies migration logic integration).
5. **Bulk insert** — creating 20 items in sequence produces the final correct carton state after all saves (ok to be wasteful mid-sequence).
6. **Observer doesn't loop** — editing cartons directly (via `CreateCartonAction`) does NOT trigger `PackingListItemObserver`. Verify by counting DB queries or by asserting no second-pass behavior.

- [ ] **Step 5: Run tests**

Run: `php artisan test --filter=PackingListItemObserverTest`

**Regression risk:** This observer fires on every legacy UI save. Some existing tests may create `PackingListItem` rows via factories and expect specific behavior. Run:

```
php artisan test --filter=PackingList
```

and triage any failures — most likely the fix is adding `withoutObservers()` to those tests if they don't care about carton sync.

---

## Task 9: Integration regression pass

**Files:** none (verification only)

- [ ] **Step 1: Run targeted regression suites**

```bash
php artisan test --filter=Shipment
php artisan test --filter=PackingList
php artisan test --filter=Logistics
```

Expected: all green. If anything is red, triage:
- **Legacy test asserting `packing_list_items` totals** → update to assert via `cartons` (the source of truth post-PR-#2).
- **Legacy test mocking `RecalculateShipmentTotalsAction`** → should still work since we kept the class name and public contract.
- **Legacy PDF snapshot test** → expect changes in `package_no` format (`BOX-001` vs `1-10`). Update snapshots intentionally.

- [ ] **Step 2: Full suite smoke**

```bash
php artisan test
```

Expected: no regressions outside the targeted areas.

- [ ] **Step 3: Manual smoke test**

1. `php artisan tinker`
2. Load a shipment with existing packing_list_items.
3. Call `app(\App\Domain\Logistics\Actions\SyncCartonsFromLegacyAction::class)->execute($shipment);`
4. Verify `$shipment->fresh()->cartons->count() > 0` and that `total_packages` / weights on the shipment are consistent with the pre-existing legacy totals (within rounding tolerance).
5. Generate a PDF via the existing Filament action — confirm it renders without errors and shows `BOX-NNN` labels.

- [ ] **Step 4: Leave uncommitted**

Per user instruction: no commits this task. The user will bundle commits at end of session.

---

## Verification Checklist (exit criteria)

Before declaring PR #2 done, verify each row:

- [ ] `PackingProgressService` exists, tests green.
- [ ] All 5 Carton actions exist (Create, AddContent, Update, Delete, DeleteContent), tests green.
- [ ] `RecalculateShipmentTotalsAction` points at cartons with shipment_items fallback, tests green.
- [ ] `PackingListPdfTemplate` produces the expected data shape for all 4 canonical scenarios; grand totals use PackingProgressService to dedupe multi-box sets.
- [ ] `PackingListItemObserver` keeps cartons in sync with legacy edits; doesn't loop.
- [ ] `php artisan test` — full suite green (or: only pre-existing failures unrelated to this PR).
- [ ] Manual smoke test: real shipment renders a V2 PDF end-to-end.
- [ ] `SplitProductAcrossCartonsAction` confirmed deferred to PR #3 — not in this PR.
- [ ] Legacy "previous incremental patch" diffs (`PackingListRelationManager`, `PackingListItem`'s `package_label` handling) untouched by this PR.

---

## Out of Scope (explicit)

- **UI changes** — `PackingListRelationManager.php` and the legacy Filament form stay as-is. Replacement is PR #3.
- **Dropping `packing_list_items`** — happens in PR #4 after production validation.
- **`SplitProductAcrossCartonsAction`** — deferred to PR #3 (requires Livewire component state).
- **`GeneratePackingListAction` rewrite** — stays legacy; still used by the current UI's "Generate from Items" button. Replacement (`GeneratePackingListV2Action`) is PR #3.
- **PDF view (`pdf.packing-list.blade.php`) changes** — keys preserved so view is untouched.
- **Commits** — user handles commits at end of session.
- **Benchmarking Livewire with 100+ cartons** — that's PR #3 concern.
