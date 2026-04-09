# Packing List Redesign — PR #3: Livewire UI + Legacy Cleanup

> **For agentic workers:** REQUIRED SUB-SKILL: `superpowers:subagent-driven-development` or `superpowers:executing-plans`. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the legacy `PackingListRelationManager` with a custom Livewire component `PackingListBuilder` that operates directly on the `cartons` / `carton_contents` tables built in PR #1 + PR #2. Expose it as a Filament sub-navigation page on the Shipment resource. Implement the deferred `SplitProductAcrossCartonsAction` and a new `GeneratePackingListV2Action` that writes to cartons. Remove the "previous incremental patch" code (`package_label` / `is_primary_package`) from the PHP side; DB columns stay for PR #4.

**Architecture:**
- `PackingListBuilder` Livewire component holds shipment-scoped UI state (notably `pendingSets`, in-memory only). All persistent writes go through the PR #2 actions — the component owns **zero** DB logic beyond what lives in actions.
- Shipment resource loses `PackingListRelationManager` from `getRelations()`; a new `ManagePackingList` Filament page renders the Livewire component and is registered via `getPages()` + `getRecordSubNavigation()` on the edit/view pages.
- `SplitProductAcrossCartonsAction` only reserves a ULID + records part labels — the pending parts live in the Livewire component's state until the operator places them in cartons. This is the spec's explicit contract.
- `GeneratePackingListV2Action` ports the "separate" and "mixed" modes from the legacy `GeneratePackingListAction` to directly create `cartons` + `carton_contents` rows, using product packaging metadata (`pcs_per_carton`, `carton_weight`, etc).
- The legacy `GeneratePackingListAction` is **not deleted** in this PR — it remains as dead code for PR #4 (alongside the legacy table drop). This keeps PR #3's diff reviewable.
- The `PackingListItemObserver` registered in PR #2 stays active. After PR #3 nothing in the admin UI writes to `packing_list_items`, so the observer becomes inert — but kept as safety net (seeds, tinker edits, legacy API).

**MVP scope decisions** (confirmed with user):
1. Integration via **custom Filament page** with record sub-navigation tab (not a hacked RelationManager).
2. **MVP UI**: inline forms instead of custom modals for Add Content; inline edit via Filament Actions where idiomatic. Split workflow included (required for multi-box scenarios).
3. **Split state** lives in Livewire `pendingSets` property, not persisted.
4. **Legacy patch removal** = code only (fillable/casts/accessors/form). DB columns stay; PR #4 drops the whole `packing_list_items` table.
5. `PackingListItemObserver` **stays registered** through PR #3. Removed in PR #4.

**Spec reference:** `docs/superpowers/specs/2026-04-08-packing-list-redesign-design.md` §UI/UX, §Backend Architecture, §Rollout Plan PR #3.

**Preconditions:**
- PR #1 code (cartons schema, models, migration action) present.
- PR #2 code (PackingProgressService, all carton actions, repointed recalc, rewritten PDF, observer) present.
- Full test suite green on Logistics filter (96+ tests). Run `php artisan test --filter=Logistics` to verify before starting.

---

## File Structure

**Created:**
- `app/Livewire/Logistics/PackingListBuilder.php` — main Livewire component (~350 lines)
- `resources/views/livewire/logistics/packing-list-builder.blade.php` — main view (~220 lines)
- `app/Domain/Logistics/Actions/SplitProductAcrossCartonsAction.php` — deferred from PR #2
- `app/Domain/Logistics/Actions/GeneratePackingListV2Action.php` — writes to cartons instead of packing_list_items
- `app/Filament/Resources/Shipments/Pages/ManagePackingList.php` — custom Filament page that hosts the Livewire component
- `resources/views/filament/resources/shipments/pages/manage-packing-list.blade.php` — page view wrapper
- `tests/Feature/Logistics/SplitProductAcrossCartonsActionTest.php`
- `tests/Feature/Logistics/GeneratePackingListV2ActionTest.php`
- `tests/Feature/Livewire/Logistics/PackingListBuilderTest.php`

**Modified:**
- `app/Filament/Resources/Shipments/ShipmentResource.php` — remove `PackingListRelationManager` from `getRelations()`; add `ManagePackingList` to `getPages()`; add static `getRecordSubNavigation()` method
- `app/Filament/Resources/Shipments/Pages/EditShipment.php` — add `getRecordSubNavigation()` delegating to resource
- `app/Filament/Resources/Shipments/Pages/ViewShipment.php` — same
- `app/Domain/Logistics/Models/PackingListItem.php` — remove `package_label`, `is_primary_package` from fillable + casts + `getProductNameAttribute()` accessor branch

**Deleted:**
- `app/Filament/Resources/Shipments/RelationManagers/PackingListRelationManager.php`

**Not touched (intentional):**
- `packing_list_items` table schema — PR #4 drops it entirely.
- `app/Domain/Logistics/Actions/GeneratePackingListAction.php` — legacy, kept as dead code through PR #4.
- `PackingListItemObserver` — stays registered; inert after UI removal.
- PDF view `pdf.packing-list.blade.php` — already compatible from PR #2.
- Migration to drop columns — deferred to PR #4.

---

## Task 1: `SplitProductAcrossCartonsAction`

**Files:**
- Create: `app/Domain/Logistics/Actions/SplitProductAcrossCartonsAction.php`
- Create: `tests/Feature/Logistics/SplitProductAcrossCartonsActionTest.php`

**Scope:** Pure function that generates a ULID and validates inputs. Does **not** write to the DB. Returns a DTO-shaped array that the Livewire component stashes in `pendingSets`.

- [ ] **Step 1: Create the DTO** (inline in the action file for brevity, or as a readonly class in `DTOs/` if preferred)

```php
final readonly class PendingMultiBoxSet
{
    /**
     * @param  array<int, string>  $partLabels
     */
    public function __construct(
        public string $setId,
        public int $shipmentItemId,
        public int $piecesPerUnit,
        public array $partLabels,
    ) {}
}
```

- [ ] **Step 2: Create the action**

```php
<?php

namespace App\Domain\Logistics\Actions;

use App\Domain\Logistics\DTOs\PendingMultiBoxSet;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\Logistics\Services\PackingProgressService;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SplitProductAcrossCartonsAction
{
    public function __construct(
        private readonly PackingProgressService $progress,
    ) {}

    /**
     * Reserve a new multi-box set for one physical unit of $item.
     *
     * Does NOT persist to the DB — the Livewire component stores the returned
     * DTO in its `pendingSets` state array. When the operator drops each part
     * into a carton, `AddContentToCartonAction` is called with the setId.
     *
     * @param  array<int, string>  $partLabels  ordered labels, min 2, e.g. ['Frame', 'Accessories']
     */
    public function execute(ShipmentItem $item, int $piecesPerUnit, array $partLabels): PendingMultiBoxSet
    {
        if (count($partLabels) < 2) {
            throw new InvalidArgumentException('A split requires at least 2 parts');
        }

        if ($piecesPerUnit <= 0) {
            throw new InvalidArgumentException('piecesPerUnit must be greater than 0');
        }

        $progress = $this->progress->forShipmentItem($item);

        if ($piecesPerUnit > $progress->remaining()) {
            throw new InvalidArgumentException(sprintf(
                'Cannot split %d pieces — only %d remaining for item %d',
                $piecesPerUnit,
                $progress->remaining(),
                $item->id,
            ));
        }

        // Normalize labels: trim, drop empties, unique-by-case-insensitive preferred but spec allows dupes
        $labels = array_values(array_filter(array_map('trim', $partLabels), fn ($l) => $l !== ''));
        if (count($labels) < 2) {
            throw new InvalidArgumentException('After trimming, fewer than 2 valid part labels remain');
        }

        return new PendingMultiBoxSet(
            setId: (string) Str::ulid(),
            shipmentItemId: $item->id,
            piecesPerUnit: $piecesPerUnit,
            partLabels: $labels,
        );
    }
}
```

- [ ] **Step 3: Test scenarios**

1. **Happy path** — 2 parts, 1 piece/unit, returns DTO with non-empty ULID, matching labels, correct itemId.
2. **3 parts** — returns DTO with all 3 labels preserved in order.
3. **Fewer than 2 parts throws** — `['Frame']` → InvalidArgumentException.
4. **Empty labels filtered** — `['Frame', '', '   ', 'Accessories']` → DTO with `['Frame', 'Accessories']`.
5. **All labels empty after trim throws** — `['  ', '']` → throws.
6. **piecesPerUnit ≤ 0 throws**.
7. **piecesPerUnit > remaining throws** — item qty=1, already packed 1 → remaining 0 → throws.
8. **No DB writes** — count `cartons_contents` before and after, must be equal.

- [ ] **Step 4: Run tests**

```
php artisan test --filter=SplitProductAcrossCartonsActionTest
```

---

## Task 2: `GeneratePackingListV2Action`

**Files:**
- Create: `app/Domain/Logistics/Actions/GeneratePackingListV2Action.php`
- Create: `tests/Feature/Logistics/GeneratePackingListV2ActionTest.php`

**Scope:** Port the "separate" and "mixed" modes from legacy `GeneratePackingListAction` to operate on `cartons` + `carton_contents`. Uses product packaging metadata (`pcs_per_carton`, `carton_weight`, `carton_net_weight`, dimensions, `carton_cbm`) the same way the legacy action does.

**Behavior (separate mode):**
- For each `ShipmentItem`:
  - Read `packaging.pcs_per_carton`. If null or zero, create 1 carton with 1 content (pieces = item.quantity).
  - Otherwise compute `fullCartons = floor(qty / pcsPerCarton)` and `remainder = qty % pcsPerCarton`.
  - Create `fullCartons` cartons, each with 1 content of `pcsPerCarton` pieces + carton-level weight/dims from packaging.
  - If `remainder > 0`, create 1 more carton with `remainder` pieces + proportional weight.

**Behavior (mixed mode):**
- Compute the max number of cartons needed across all items (using each item's `pcsPerCarton`).
- Create that many cartons.
- For each carton index 0..maxBoxes-1:
  - For each item, if it still has pieces to pack, add a content for this carton with `min(pcsPerCarton, remaining)` pieces.
- Weight/dim overrides come from the `$config` array (legacy behavior preserved).

**Pre-execution:** Wipe existing cartons for the shipment (`$shipment->cartons()->each(...)` with cascade) — mirrors legacy which starts with `$shipment->packingListItems()->delete()`.

- [ ] **Step 1: Create the action**

High-level signature:

```php
class GeneratePackingListV2Action
{
    public function __construct(
        private readonly CreateCartonAction $createCarton,
        private readonly RecalculateShipmentTotalsAction $recalc,
    ) {}

    public function execute(Shipment $shipment, bool $mixed = false, array $mixedBoxConfig = []): int
    {
        // Wipe existing cartons (contents cascade)
        DB::transaction(function () use ($shipment) {
            $shipment->cartons()->each(function ($carton) {
                $carton->contents()->delete();
                $carton->delete();
            });
        });

        $count = $mixed
            ? $this->generateMixed($shipment, $mixedBoxConfig)
            : $this->generateSeparate($shipment);

        $this->recalc->execute($shipment);

        return $count;
    }

    // Port generateSeparate, generateMixed logic from legacy action — writing to cartons+contents
}
```

- [ ] **Step 2: Implementation details**

**`generateSeparate`:** Iterate shipment items. For each, call `CreateCartonAction` for each physical carton needed, then directly insert a `CartonContent` row with the pieces count. Use `CreateCartonAction` so labels are auto-generated and consistent.

**`generateMixed`:** Calculate `$maxBoxes = max(ceil(qty / pcsPerCarton))` across items. Create `$maxBoxes` cartons via `CreateCartonAction` with box-level dims from `$config`. Then for each carton, walk items and create a content row for each item that still has remaining pieces (amount = `min(pcsPerCarton, remaining)`).

**Edge cases:**
- Item with qty 0 → skipped.
- Item with no `packaging` relation → 1 carton with 1 content holding full qty (fallback to single carton).
- Empty shipment (zero items) → returns 0, no cartons created.

- [ ] **Step 3: Test scenarios** (~10 tests)

1. Separate mode, 1 item qty=100 pcs_per_carton=10 → 10 cartons, each with content pieces=10.
2. Separate mode with remainder: qty=95 pcs_per_carton=10 → 10 cartons (9 full + 1 remainder with pieces=5).
3. Separate mode, item without packaging → 1 carton with 1 content pieces=qty.
4. Separate mode, multiple items → independent carton sequences, auto-labels sequential.
5. Mixed mode, 2 items qty=10+20, pcs_per_carton=5 → maxBoxes=4, 4 cartons, each with 2 contents (one per item, appropriately distributed).
6. Mixed mode, box-level config (gross_weight=15) → all cartons carry weight 15.
7. Running twice wipes old cartons → no duplicates.
8. Recalc dispatched → shipment totals reflect new cartons.
9. Empty shipment → returns 0, no cartons.
10. Mixed mode with config packaging_type override → carton stores that type.

- [ ] **Step 4: Run tests**

```
php artisan test --filter=GeneratePackingListV2ActionTest
```

---

## Task 3: Remove legacy patch from `PackingListItem` (code only)

**Files:**
- Modify: `app/Domain/Logistics/Models/PackingListItem.php`

- [ ] **Step 1: Remove fields from fillable**

In `$fillable`, delete `'package_label'` and `'is_primary_package'`.

- [ ] **Step 2: Remove from casts**

In `casts()`, delete `'is_primary_package' => 'boolean'`.

- [ ] **Step 3: Simplify the `getProductNameAttribute` accessor**

Revert to the pre-patch version:

```php
public function getProductNameAttribute(): string
{
    return $this->shipmentItem?->product_name
        ?? $this->description
        ?? '—';
}
```

- [ ] **Step 4: Verify no other code references these fields**

```
grep -rn "package_label\|is_primary_package" app/ --include="*.php"
```

Expected: only hits inside `MigratePackingListItemsToCartonsAction.php` (which *reads* legacy data during observer sync — this is correct, keep it). If anything else shows up, investigate.

The previously-modified `PackingListRelationManager.php` will be **deleted** in Task 7, so any references there are moot.

- [ ] **Step 5: Run a quick regression**

```
php artisan test --filter=PackingList
```

The observer migration test that uses `'is_primary_package' => true` in `PackingListItem::create(...)` should still work — **wait**: since the DB column still exists, but fillable no longer lists it, Laravel will silently drop the assignment. That breaks the multi-box-siblings observer test. **Mitigation:** in the observer test, use `DB::table('packing_list_items')->insert()` or keep the columns fillable for now and only remove them in PR #4.

**Decision for this PR:** Remove from fillable/casts only if it doesn't break tests. If the test breaks, leave `is_primary_package` in fillable (silent — no UI touches it anymore) and note in code comment that it's kept for the observer migration path until PR #4 drops the table.

Concretely: **try removing first**, run the observer test, and if red, revert the fillable change and add a code comment explaining the coupling. Either outcome is acceptable as long as the UI layer (Task 4) doesn't read these fields.

---

## Task 4: `PackingListBuilder` Livewire component

**Files:**
- Create: `app/Livewire/Logistics/PackingListBuilder.php`

**Responsibilities:**
- Hold `Shipment $shipment` and load cartons + progress reactively.
- Maintain `pendingSets: array<string, PendingMultiBoxSet>` in component state (keyed by setId).
- Expose actions that delegate to Domain Actions.
- Expose computed properties for the view.

**Properties:**

```php
public Shipment $shipment;

/** @var array<int, array{shipmentItemId: int, piecesPerUnit: int, partLabels: array, placed: array<int, ?int>}> */
public array $pendingSets = [];

// Transient UI state for the "add content" inline form
public ?int $addContentCartonId = null;
public ?int $addContentItemId = null;
public int $addContentPieces = 0;

// Transient UI state for the split form
public ?int $splitItemId = null;
public int $splitPartsCount = 2;
public array $splitPartLabels = ['', ''];
public int $splitPiecesPerUnit = 1;
```

**Computed properties** (via Livewire 3 `#[Computed]`):

```php
#[Computed]
public function products(): Collection
{
    // ShipmentItem with progress + pending parts attached
    $progress = app(PackingProgressService::class)->forShipment($this->shipment);
    return $this->shipment->items()->with('proformaInvoiceItem.product')->get()
        ->map(fn ($item) => [
            'item' => $item,
            'progress' => $progress[$item->id] ?? null,
            'pending' => $this->pendingPartsForItem($item->id),
        ]);
}

#[Computed]
public function cartons(): Collection
{
    return $this->shipment->cartons()->with('contents.shipmentItem.proformaInvoiceItem.product')->orderBy('sort_order')->orderBy('label')->get();
}
```

**Actions:**

```php
public function createCarton(): void
{
    app(CreateCartonAction::class)->execute($this->shipment);
    unset($this->cartons, $this->products); // bust computed cache
}

public function deleteCarton(int $cartonId): void
{
    $carton = $this->shipment->cartons()->findOrFail($cartonId);
    app(DeleteCartonAction::class)->execute($carton);
    unset($this->cartons, $this->products);
}

public function startAddContent(int $cartonId, int $itemId): void
{
    $this->addContentCartonId = $cartonId;
    $this->addContentItemId = $itemId;
    $remaining = app(PackingProgressService::class)
        ->forShipmentItem($this->shipment->items()->findOrFail($itemId))
        ->remaining();
    $this->addContentPieces = $remaining;
}

public function confirmAddContent(): void
{
    if (! $this->addContentCartonId || ! $this->addContentItemId || $this->addContentPieces <= 0) {
        $this->cancelAddContent();
        return;
    }

    try {
        app(AddContentToCartonAction::class)->execute(
            $this->shipment->cartons()->findOrFail($this->addContentCartonId),
            $this->shipment->items()->findOrFail($this->addContentItemId),
            $this->addContentPieces,
        );
        Notification::make()->success()->title('Content added')->send();
    } catch (\InvalidArgumentException $e) {
        Notification::make()->danger()->title('Cannot add content')->body($e->getMessage())->send();
    }

    $this->cancelAddContent();
    unset($this->cartons, $this->products);
}

public function cancelAddContent(): void
{
    $this->addContentCartonId = null;
    $this->addContentItemId = null;
    $this->addContentPieces = 0;
}

public function deleteContent(int $contentId): void
{
    $content = CartonContent::findOrFail($contentId);
    app(DeleteCartonContentAction::class)->execute($content);
    unset($this->cartons, $this->products);
}

public function startSplit(int $itemId): void
{
    $this->splitItemId = $itemId;
    $this->splitPartsCount = 2;
    $this->splitPartLabels = ['Part 1', 'Part 2'];
    $this->splitPiecesPerUnit = 1;
}

public function adjustSplitPartsCount(): void
{
    // Called when splitPartsCount changes — resize splitPartLabels
    $count = max(2, (int) $this->splitPartsCount);
    while (count($this->splitPartLabels) < $count) {
        $this->splitPartLabels[] = 'Part ' . (count($this->splitPartLabels) + 1);
    }
    $this->splitPartLabels = array_slice($this->splitPartLabels, 0, $count);
}

public function confirmSplit(): void
{
    try {
        $pending = app(SplitProductAcrossCartonsAction::class)->execute(
            $this->shipment->items()->findOrFail($this->splitItemId),
            $this->splitPiecesPerUnit,
            $this->splitPartLabels,
        );

        $this->pendingSets[$pending->setId] = [
            'shipmentItemId' => $pending->shipmentItemId,
            'piecesPerUnit' => $pending->piecesPerUnit,
            'partLabels' => $pending->partLabels,
            'placed' => array_fill(0, count($pending->partLabels), null),
        ];

        Notification::make()->success()->title('Split created — place parts in cartons')->send();
    } catch (\InvalidArgumentException $e) {
        Notification::make()->danger()->title('Cannot split')->body($e->getMessage())->send();
    }

    $this->cancelSplit();
}

public function cancelSplit(): void
{
    $this->splitItemId = null;
    $this->splitPartsCount = 2;
    $this->splitPartLabels = ['', ''];
    $this->splitPiecesPerUnit = 1;
}

public function cancelPendingSet(string $setId): void
{
    unset($this->pendingSets[$setId]);
}

public function placePendingPart(string $setId, int $partIndex, int $cartonId): void
{
    if (! isset($this->pendingSets[$setId])) {
        return;
    }

    $set = $this->pendingSets[$setId];
    $partLabel = $set['partLabels'][$partIndex] ?? null;

    if ($partLabel === null || ($set['placed'][$partIndex] ?? null) !== null) {
        return;
    }

    try {
        app(AddContentToCartonAction::class)->execute(
            $this->shipment->cartons()->findOrFail($cartonId),
            $this->shipment->items()->findOrFail($set['shipmentItemId']),
            $set['piecesPerUnit'],
            $partLabel,
            $setId,
        );

        $this->pendingSets[$setId]['placed'][$partIndex] = $cartonId;

        // If all parts placed, the set is complete — drop from pending
        $allPlaced = ! in_array(null, $this->pendingSets[$setId]['placed'], true);
        if ($allPlaced) {
            unset($this->pendingSets[$setId]);
            Notification::make()->success()->title('Multi-box set complete')->send();
        }
    } catch (\InvalidArgumentException $e) {
        Notification::make()->danger()->title('Cannot place part')->body($e->getMessage())->send();
    }

    unset($this->cartons, $this->products);
}

public function generateFromItems(bool $mixed = false, array $config = []): void
{
    $created = app(GeneratePackingListV2Action::class)->execute($this->shipment, $mixed, $config);
    Notification::make()->success()->title("Generated {$created} carton group(s)")->send();
    $this->pendingSets = []; // wipe — existing pending sets no longer valid
    unset($this->cartons, $this->products);
}
```

**Helpers:**

```php
private function pendingPartsForItem(int $itemId): array
{
    $parts = [];
    foreach ($this->pendingSets as $setId => $set) {
        if ($set['shipmentItemId'] !== $itemId) {
            continue;
        }
        foreach ($set['partLabels'] as $index => $label) {
            if (($set['placed'][$index] ?? null) === null) {
                $parts[] = ['setId' => $setId, 'index' => $index, 'label' => $label];
            }
        }
    }
    return $parts;
}

public function render()
{
    return view('livewire.logistics.packing-list-builder');
}
```

**Mounting:**

```php
public function mount(Shipment $shipment): void
{
    $this->shipment = $shipment;
}
```

- [ ] **Step 1: Implement the component** per the skeleton above
- [ ] **Step 2: Verify no syntax errors**: `php -l app/Livewire/Logistics/PackingListBuilder.php`

---

## Task 5: Blade view for `PackingListBuilder`

**Files:**
- Create: `resources/views/livewire/logistics/packing-list-builder.blade.php`

**Layout:** Two-column grid (left: products with progress, right: cartons) using Tailwind via Filament theme.

Skeleton:

```blade
<div class="space-y-4">
    {{-- Header --}}
    <div class="flex items-center justify-between">
        <h2 class="text-xl font-semibold">Packing List · {{ $shipment->reference }}</h2>
        <div class="flex gap-2">
            <x-filament::button wire:click="generateFromItems(false)" color="gray" icon="heroicon-o-sparkles">
                Generate from Items
            </x-filament::button>
            <x-filament::button wire:click="createCarton" icon="heroicon-o-plus">
                + Box
            </x-filament::button>
        </div>
    </div>

    {{-- Split modal (inline, shown when splitItemId != null) --}}
    @if($splitItemId)
        <div class="rounded-lg border border-primary-500 bg-primary-50 p-4 dark:bg-primary-950">
            <h3 class="font-medium mb-2">Split product across cartons</h3>
            <div class="space-y-2">
                <div>
                    <label class="text-sm">Pieces per unit</label>
                    <input type="number" min="1" wire:model.live="splitPiecesPerUnit" class="fi-input" />
                </div>
                <div>
                    <label class="text-sm">Number of parts</label>
                    <input type="number" min="2" max="10" wire:model.live="splitPartsCount" wire:change="adjustSplitPartsCount" class="fi-input" />
                </div>
                @foreach(range(0, $splitPartsCount - 1) as $i)
                    <div>
                        <label class="text-sm">Part {{ $i + 1 }} label</label>
                        <input type="text" wire:model="splitPartLabels.{{ $i }}" class="fi-input" />
                    </div>
                @endforeach
                <div class="flex gap-2 pt-2">
                    <x-filament::button wire:click="confirmSplit">Create placeholders</x-filament::button>
                    <x-filament::button wire:click="cancelSplit" color="gray">Cancel</x-filament::button>
                </div>
            </div>
        </div>
    @endif

    {{-- Two-column layout --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        {{-- Left: Products --}}
        <div class="space-y-2">
            <h3 class="font-medium text-sm uppercase text-gray-500">Products</h3>
            @foreach($this->products as $row)
                @include('livewire.logistics.partials.product-row', ['row' => $row])
            @endforeach
        </div>

        {{-- Right: Cartons --}}
        <div class="space-y-2">
            <h3 class="font-medium text-sm uppercase text-gray-500">Cartons ({{ $this->cartons->count() }})</h3>
            @foreach($this->cartons as $carton)
                @include('livewire.logistics.partials.carton-card', ['carton' => $carton])
            @endforeach
        </div>
    </div>
</div>
```

**Partials** (two additional views — both created inline rather than separate files for MVP simplicity, or as partials if the main view gets unwieldy):

For MVP simplicity, inline everything in the main view file. Split into partials only if it exceeds ~350 lines.

**Product row structure:**

```blade
<div class="rounded-lg border p-3 flex items-start justify-between">
    <div class="flex-1">
        <div class="font-medium">{{ $row['item']->product_name }}</div>
        <div class="text-sm text-gray-500">
            {{ $row['progress']->packedComplete ?? 0 }}/{{ $row['progress']->total ?? $row['item']->quantity }}
            @if($row['progress'])
                · <span class="uppercase text-xs">{{ str_replace('_', ' ', strtolower($row['progress']->status->value)) }}</span>
            @endif
        </div>

        {{-- Pending placeholders for this item --}}
        @foreach($row['pending'] as $pending)
            <div class="mt-1 pl-4 text-xs text-primary-600 flex items-center gap-2">
                <span>└ {{ $pending['label'] }}</span>
                <select wire:change="placePendingPart('{{ $pending['setId'] }}', {{ $pending['index'] }}, $event.target.value)" class="fi-select text-xs">
                    <option value="">Choose box...</option>
                    @foreach($this->cartons as $carton)
                        <option value="{{ $carton->id }}">{{ $carton->label }}</option>
                    @endforeach
                </select>
                <button wire:click="cancelPendingSet('{{ $pending['setId'] }}')" class="text-red-500 text-xs">×</button>
            </div>
        @endforeach
    </div>

    <div class="flex flex-col gap-1">
        @if($row['progress'] && $row['progress']->remaining() > 0)
            <x-filament::button size="xs" wire:click="startSplit({{ $row['item']->id }})" color="gray">✂ Split</x-filament::button>
        @endif
    </div>
</div>
```

**Carton card structure:**

```blade
<div class="rounded-lg border p-3">
    <div class="flex items-center justify-between mb-2">
        <div class="font-medium">{{ $carton->label }}</div>
        <div class="flex items-center gap-2 text-sm text-gray-500">
            @if($carton->gross_weight)
                <span>{{ number_format((float) $carton->gross_weight, 1) }} kg</span>
            @endif
            <button wire:click="deleteCarton({{ $carton->id }})" wire:confirm="Delete this carton?" class="text-red-500">×</button>
        </div>
    </div>

    <div class="space-y-1">
        @forelse($carton->contents as $content)
            <div class="flex items-center justify-between text-sm">
                <div>
                    • {{ $content->shipmentItem?->product_name ?? '—' }}
                    @if($content->part_label)
                        <span class="text-xs text-gray-500">[{{ $content->part_label }}]</span>
                    @endif
                    @if($content->multi_box_set_id)
                        <span class="text-xs text-primary-500">#{{ substr($content->multi_box_set_id, -4) }}</span>
                    @endif
                    · {{ $content->pieces }} pcs
                </div>
                <button wire:click="deleteContent({{ $content->id }})" class="text-red-500 text-xs">×</button>
            </div>
        @empty
            <div class="text-xs text-gray-400 italic">No contents yet</div>
        @endforelse
    </div>

    {{-- Inline "add content" form --}}
    @if($addContentCartonId === $carton->id)
        <div class="mt-2 pt-2 border-t space-y-2">
            <select wire:model.live="addContentItemId" class="fi-select w-full">
                <option value="">Select product...</option>
                @foreach($this->products as $row)
                    @if($row['progress'] && $row['progress']->remaining() > 0)
                        <option value="{{ $row['item']->id }}">
                            {{ $row['item']->product_name }} (remaining: {{ $row['progress']->remaining() }})
                        </option>
                    @endif
                @endforeach
            </select>
            <input type="number" min="1" wire:model="addContentPieces" placeholder="Pieces" class="fi-input w-full" />
            <div class="flex gap-2">
                <x-filament::button size="xs" wire:click="confirmAddContent">Add</x-filament::button>
                <x-filament::button size="xs" wire:click="cancelAddContent" color="gray">Cancel</x-filament::button>
            </div>
        </div>
    @else
        <button wire:click="startAddContent({{ $carton->id }}, 0)" class="mt-2 text-xs text-primary-600">
            ⊕ Add content
        </button>
    @endif
</div>
```

- [ ] **Step 1: Write the full view file**
- [ ] **Step 2: Check it compiles** via `php artisan view:clear && php artisan view:cache` (any Blade syntax errors surface here)

**Note on Filament blade components:** Verify `x-filament::button` is available in Livewire context. If not, fall back to `<button class="fi-btn fi-btn-primary">` or whatever class path the project uses. A quick check:

```
grep -rn "x-filament::button" resources/views/livewire/ 2>/dev/null | head
```

If none, use plain `<button>` with Filament's fi-btn classes.

---

## Task 6: `ManagePackingList` Filament page + Blade wrapper

**Files:**
- Create: `app/Filament/Resources/Shipments/Pages/ManagePackingList.php`
- Create: `resources/views/filament/resources/shipments/pages/manage-packing-list.blade.php`

- [ ] **Step 1: Page class**

```php
<?php

namespace App\Filament\Resources\Shipments\Pages;

use App\Filament\Resources\Shipments\ShipmentResource;
use Filament\Resources\Pages\Page;

class ManagePackingList extends Page
{
    protected static string $resource = ShipmentResource::class;

    protected string $view = 'filament.resources.shipments.pages.manage-packing-list';

    protected static ?string $title = 'Packing List';

    protected static ?string $navigationLabel = 'Packing List';

    public $record; // Filament injects the shipment record

    public function mount(int | string $record): void
    {
        $this->record = ShipmentResource::getModel()::findOrFail($record);
    }

    public function getTitle(): string | \Illuminate\Contracts\Support\Htmlable
    {
        return 'Packing List · ' . $this->record->reference;
    }
}
```

- [ ] **Step 2: Page view** (embeds the Livewire component)

```blade
<x-filament-panels::page>
    <livewire:logistics.packing-list-builder :shipment="$record" />
</x-filament-panels::page>
```

- [ ] **Step 3: Route registration** — happens in Task 7 via `ShipmentResource::getPages()`.

---

## Task 7: Wire `ShipmentResource` — sub-navigation + page registration

**Files:**
- Modify: `app/Filament/Resources/Shipments/ShipmentResource.php`
- Modify: `app/Filament/Resources/Shipments/Pages/EditShipment.php`
- Modify: `app/Filament/Resources/Shipments/Pages/ViewShipment.php`
- Delete: `app/Filament/Resources/Shipments/RelationManagers/PackingListRelationManager.php`

- [ ] **Step 1: Remove the RelationManager from `getRelations()`**

In `ShipmentResource.php`:

```php
public static function getRelations(): array
{
    return [
        ItemsRelationManager::class,
        // PackingListRelationManager removed — replaced by ManagePackingList page
        AdditionalCostsRelationManager::class,
        PaymentScheduleRelationManager::class,
        DocumentsRelationManager::class,
    ];
}
```

Remove the `use` statement for `PackingListRelationManager` too.

- [ ] **Step 2: Register the new page in `getPages()`**

```php
public static function getPages(): array
{
    return [
        'index' => ListShipments::route('/'),
        'create' => CreateShipment::route('/create'),
        'view' => ViewShipment::route('/{record}'),
        'edit' => EditShipment::route('/{record}/edit'),
        'packing-list' => ManagePackingList::route('/{record}/packing-list'),
    ];
}
```

Add `use App\Filament\Resources\Shipments\Pages\ManagePackingList;` at the top.

- [ ] **Step 3: Add `getRecordSubNavigation()`**

```php
public static function getRecordSubNavigation(\Filament\Resources\Pages\Page $page): array
{
    return $page->generateNavigationItems([
        Pages\EditShipment::class,
        Pages\ManagePackingList::class,
    ]);
}
```

- [ ] **Step 4: Opt-in the child pages**

In `EditShipment.php` add:

```php
public function getSubNavigation(): array
{
    return static::$resource::getRecordSubNavigation($this);
}
```

Same in `ViewShipment.php` and `ManagePackingList.php`.

**Note:** Filament 4's API for sub-navigation may use `getRecordSubNavigation` on the resource class (preferred) or a trait. Look at existing resources in the project that use sub-navigation; if none, follow Filament 4 docs pattern. Validate by browsing to `/admin/shipments/{id}/edit` and seeing the tab.

- [ ] **Step 5: Delete `PackingListRelationManager.php`**

```
rm app/Filament/Resources/Shipments/RelationManagers/PackingListRelationManager.php
```

This is safe because:
- No other code references it (verify: `grep -rn "PackingListRelationManager" app/ --include="*.php"`).
- The uncommitted "previous incremental patch" diffs on this file become moot — deletion supersedes them.

- [ ] **Step 6: Smoke test via browser**

Cannot run from CLI; document for user: load `/admin/shipments/{id}/edit`, verify the "Packing List" tab appears in sub-navigation, click it, confirm the Livewire component renders with cartons visible.

Alternative automated check: `php artisan route:list | grep packing-list` — the new route should exist.

---

## Task 8: Tests

**Files:**
- Create: `tests/Feature/Livewire/Logistics/PackingListBuilderTest.php`

Use Livewire's testing helpers (`Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])`).

**Test scenarios:**

1. **Mount loads shipment** — component renders without error.
2. **Create carton** — calling `createCarton()` results in 1 carton in DB.
3. **Delete carton** — creates then deletes; DB count returns to 0.
4. **Start add content** — sets `addContentCartonId` and pre-fills `addContentPieces` to remaining.
5. **Confirm add content** — creates a `CartonContent` row.
6. **Confirm add content with over-remaining** — shows error notification, no row created.
7. **Cancel add content** — resets transient state, no DB changes.
8. **Delete content** — removes the row.
9. **Start split** — initializes split form state.
10. **Confirm split** — adds entry to `pendingSets`, no DB rows.
11. **Place pending part** — calls `AddContentToCartonAction`, updates `placed` index, creates a CartonContent with correct `multi_box_set_id`.
12. **Place all pending parts** — set disappears from `pendingSets` once complete.
13. **Cancel pending set** — removes from `pendingSets`, no DB impact.
14. **Generate from items** — creates cartons via `GeneratePackingListV2Action`, clears `pendingSets`.
15. **Computed `products` returns progress DTOs**.
16. **Computed `cartons` ordered by sort_order**.

Example test:

```php
public function test_create_carton_creates_row(): void
{
    [$shipment, ] = $this->makeShipment();

    Livewire::test(PackingListBuilder::class, ['shipment' => $shipment])
        ->call('createCarton')
        ->assertOk();

    $this->assertEquals(1, $shipment->cartons()->count());
}
```

Use a helper `makeShipment()` to build a Shipment with 1 ShipmentItem (copy pattern from `AddContentToCartonActionTest`).

- [ ] **Step 1: Write all 16 test scenarios** (or the essential ~10 — prioritize create/delete/add/split paths)
- [ ] **Step 2: Run**

```
php artisan test --filter=PackingListBuilderTest
```

---

## Task 9: Integration regression pass

**Files:** none (verification only)

- [ ] **Step 1: Logistics sweep**

```
php artisan test --filter=Logistics
```

Expected: all green. Special attention to:
- `PackingListItemObserverTest` — still passes (observer stays registered).
- `PackingListPdfV2Test` — still passes (template unchanged since PR #2).
- `MigratePackingListItemsToCartonsActionTest` — still passes (action unchanged).

- [ ] **Step 2: Full suite**

```
php artisan test
```

Expected: same 2 pre-existing failures as PR #2 (GeneratePaymentScheduleActionTest, ProductionActualsGridTest), nothing new.

- [ ] **Step 3: Route check**

```
php artisan route:list | grep shipment
```

Expected: a new route like `shipments/{record}/packing-list` appears.

- [ ] **Step 4: Browser smoke test** (user-executed)

Document for user: open a Shipment with cartons in the admin panel, confirm the "Packing List" tab renders, exercise each action (+ Box, add content, delete, split + place).

- [ ] **Step 5: Grep check for orphan references**

```
grep -rn "PackingListRelationManager" app/ --include="*.php"
grep -rn "package_label\|is_primary_package" app/ --include="*.php"
```

Expected:
- `PackingListRelationManager`: zero hits (file deleted, imports removed).
- `package_label` / `is_primary_package`: only inside `MigratePackingListItemsToCartonsAction.php` (reads legacy data during observer sync — keep).

- [ ] **Step 6: Leave uncommitted**

Per user instruction: commits happen at end of session.

---

## Verification Checklist (exit criteria)

- [ ] `SplitProductAcrossCartonsAction` created with full validation + tests green.
- [ ] `GeneratePackingListV2Action` ports separate + mixed modes + tests green.
- [ ] `PackingListItem` model no longer references `package_label` / `is_primary_package` (or change is noted as blocked by observer test).
- [ ] `PackingListBuilder` Livewire component + view exist and render.
- [ ] `ManagePackingList` Filament page registered, sub-navigation tab visible.
- [ ] `PackingListRelationManager.php` deleted.
- [ ] `ShipmentResource::getRelations()` no longer contains `PackingListRelationManager`.
- [ ] Livewire component tests cover create/delete/add/split/place paths.
- [ ] `php artisan test --filter=Logistics` — all green.
- [ ] `php artisan test` — full suite, same pre-existing failures only.
- [ ] Route `shipments/{record}/packing-list` registered.
- [ ] No orphan references to `PackingListRelationManager`.
- [ ] No references to `package_label` / `is_primary_package` outside `MigratePackingListItemsToCartonsAction`.

---

## Out of Scope (explicit)

- **Dropping `packing_list_items` table** — PR #4.
- **Dropping `package_label` / `is_primary_package` columns from schema** — PR #4.
- **Removing `PackingListItemObserver`** — PR #4 (stays inert through PR #3).
- **Removing legacy `GeneratePackingListAction`** — PR #4 (stays as dead code).
- **Renumber packages action** — legacy feature; port later if operators request.
- **Carton edit modal (dimensions, weight detail)** — MVP relies on `UpdateCartonAction` being callable; UI for it is a nice-to-have. If time permits at end of PR, add a simple Filament Action in the carton card. Otherwise defer.
- **Drag-and-drop** — explicit non-goal per spec.
- **Real-time collaborative editing** — explicit non-goal.
- **Commits** — user handles at end of session.
