# Packing List Redesign — Box-Centric Model with Multi-Box Sets

## Context

The current Packing List feature in the Shipment module models data as `packing_list_items` rows where each row represents a *range of cartons containing one product*. The formula `total_quantity = quantity × qty_per_carton` assumes every box has at least one whole piece. This model breaks for several real-world scenarios encountered by Impex operators:

1. **Normal** — A product packed with standard cartons (e.g., 100 sandals at 10/carton = 10 boxes).
2. **Overflow** — A product whose remainder doesn't fill a box and gets placed in a mixed box with other products (e.g., 95 sandals = 9 full boxes + 5 in a shared box).
3. **Multi-box product** — One physical unit spans multiple boxes (e.g., a gym machine packed in 2 cartons: frame + accessories).
4. **Multi-box + sharing** — A multi-box product whose secondary box is itself shared with other products (e.g., the accessories box also contains sandals and a third product).

A previous incremental patch added `package_label` and `is_primary_package` fields to handle scenario (3). It works but the form remains cluttered, scenarios (2) and (4) still require operator workarounds (entering identical carton ranges manually), and operators frequently make errors: duplicate carton numbers, mistyped labels, double-counted pieces.

The goal is a UI that makes these errors **structurally impossible** by promoting "carton" to a first-class entity with auto-generated labels, and by introducing an explicit "multi-box set" concept that ties parts of a single physical unit together regardless of which boxes they occupy.

## Goals

- Support all 4 scenarios in a single, unified data model.
- Auto-generate box labels (operators never type them).
- Prevent double-counting of pieces when one unit spans multiple boxes.
- Force operators to allocate every part of a multi-box set before considering the unit "packed."
- Replace the current cluttered form with a 2-column visual layout (products on the left, cartons on the right).
- Migrate existing production data without loss.

## Non-Goals

- Drag-and-drop interaction (click-only for now; revisit in v2 if requested).
- Real-time multi-user collaboration with websockets (optimistic locking only).
- Auto-balancing of products across cartons (operator decides).
- Saved packing templates per product (out of scope).
- Renumbering of box labels after deletes (gaps are acceptable; manual "Renumber" action remains).

## Data Model

Two new tables. The legacy `packing_list_items` table is retained during the rollout and dropped in a separate migration after production validation.

### `cartons`

```sql
id                  bigint pk
shipment_id         fk -> shipments cascade
label               varchar(50)         -- auto: "BOX-001", unique per shipment
container_number    varchar(50) null    -- maritime container (CCLU7730065)
pallet_number       int null            -- physical pallet
packaging_type      enum                -- CARTON/BAG/DRUM/WOOD_BOX/BULK
gross_weight        decimal(10,3) null
net_weight          decimal(10,3) null
length              decimal(8,2) null
width               decimal(8,2) null
height              decimal(8,2) null
volume              decimal(10,4) null
notes               text null
sort_order          int default 0
timestamps

unique(shipment_id, label)
index(shipment_id, sort_order)
```

### `carton_contents`

```sql
id                  bigint pk
carton_id           fk -> cartons cascade
shipment_item_id    fk -> shipment_items nullOnDelete
pieces              int                       -- pieces of this product in this box
part_label          varchar(100) null         -- "Frame", "Accessories"
multi_box_set_id    char(26) null             -- ULID, groups parts of one physical unit
weight_share        decimal(10,3) null        -- optional per-content weight breakdown
sort_order          int default 0
timestamps

index(multi_box_set_id)
index(shipment_item_id)
```

### Counting rules (canonical)

For a given `shipment_item_id`:

- **Contents with `multi_box_set_id = NULL`** contribute `SUM(pieces)` to the packed total.
- **Contents with `multi_box_set_id != NULL`** group by `(shipment_item_id, multi_box_set_id)`. Each distinct group contributes the group's `pieces` value (a single number, since all parts of a set carry the same `pieces` — `SplitProductAcrossCartonsAction` enforces this at split time, and the schema has no UI surface to mutate it independently afterwards).

This is the single source of truth — `PackingProgressService` implements it once and every other consumer (UI, PDF, recalculation action) calls it.

### Weight rules

- `cartons.gross_weight` is the canonical weight of the box. Operator weighs the closed box and types it once.
- `carton_contents.weight_share` is **optional**. Used only when an operator wants to attribute portions of a mixed box's weight to specific contents. The field is all-or-nothing per carton: either every content in a given carton has `weight_share` set, or none does. When all contents have it, the system validates that `SUM(weight_share) == cartons.gross_weight` (with a small tolerance for rounding, ±0.001 kg). The Livewire component enforces this by requiring the operator to fill all weights or clear all of them in a single edit modal.
- The PDF prefers `weight_share` when available; otherwise it shows the carton's `gross_weight` on the first content row and leaves subsequent content rows blank (matching today's "mixed carton" behavior).

### Auto-generated labels

When a carton is created, `CreateCartonAction` queries `MAX(numeric_part(label))` for the shipment and generates `BOX-` + zero-padded next integer. Operators never type the label. Renaming is permitted via the carton edit form but requires a confirmation modal warning that PDFs already sent reference the old label.

After deletes, gaps remain (BOX-005 deleted leaves BOX-004 and BOX-006). The existing manual "Renumber Packages" action is preserved and adapted to the new schema for operators who want sequential numbering.

## UI / UX

Replace the current `PackingListRelationManager` with a custom Livewire component (`App\Livewire\Logistics\PackingListBuilder`) embedded in the Shipment resource via a custom page tab. Layout is two columns:

```
┌────────────────────────────────────────────────────────────────────────┐
│ Packing List · Shipment SHIP-001                                       │
│                                          [Generate from Items] [+ Box] │
├──────────────────────────────────┬─────────────────────────────────────┤
│ PRODUCTS                         │ CARTONS (5)            Total: 87 kg │
│                                  │                                     │
│ ▸ Machine X        1/1   ✓       │ ┌─ BOX-001 ────────────── 35 kg ─┐ │
│   ✂ Split  ⊕ Add to box          │ │ Container: CCLU7730065 · PLT-01 │ │
│                                  │ │ • Machine X · Frame  [set #a]   │ │
│ ▸ Sandals       95/100   ⏳      │ │           [⋯ edit] [✕ remove]   │ │
│   ⊕ Add to box                   │ └─────────────────────────────────┘ │
│                                  │                                     │
│ ▸ Socks         12/20    ⏳      │ ┌─ BOX-002 ────────────── 18 kg ─┐ │
│                                  │ │ • Machine X · Accessories [#a]  │ │
│ ▸ Accessory Y    0/3     ❗      │ │ • Sandals · 5 pcs               │ │
│                                  │ │ • Socks   · 8 pcs               │ │
│                                  │ │           [⊕ Add content]       │ │
│                                  │ └─────────────────────────────────┘ │
└──────────────────────────────────┴─────────────────────────────────────┘
```

### Left column — Products

For each `ShipmentItem`, render a row showing:

- Product name + `packed/total` + status icon:
  - `✓` complete (`packed_complete >= total`)
  - `🔧` in progress (any multi-box set has unallocated parts)
  - `⏳` partial (some pieces packed, no pending parts)
  - `❗` zero (nothing packed yet)
- Inline actions:
  - **`⊕ Add to box`** — opens a dropdown listing existing cartons + `[+ New box]`. Selecting one opens the "Add content" modal.
  - **`✂ Split`** — opens the "Split product across cartons" modal.

Products with a multi-box set in progress render their pending parts as sub-rows:

```
▸ Machine X        0/1   🔧
   └ Frame         [⊕ choose box]
   └ Accessories   [⊕ choose box]
```

The product line shows `0/1` (not `1/1`) until **all** parts have been placed in some carton — the unit is "in assembly" and not counted as packed.

### Right column — Cartons

Each carton renders as a collapsible card:

- Header: `BOX-XXX` · weight · `[⋯]` menu (edit / delete)
- Sub-header: container number + pallet number (editable via the `[⋯ edit]` action)
- Content list with set badges (`[#a]`) when applicable, showing a short hash of the `multi_box_set_id`
- Footer: `[⊕ Add content]` (alternative entry point to the same modal)

A `[+ Box]` button in the page header creates an empty carton with an auto-generated label.

### Modal — "Add content to BOX-XXX"

```
Product:  [Machine X       ▾]   ← only products with remaining > 0
Pieces:   [ 5 ]                 ← max = remaining; pre-filled with full remaining
Notes:    [                ]    ← optional
[Cancel] [Add]
```

### Modal — "Split product across cartons"

```
Splitting "Machine X" — qty 1
─────────────────────────────
How many parts? [ 2 ]
Part 1 label:   [Frame       ]
Part 2 label:   [Accessories ]
─────────────────────────────
ℹ This will create 2 placeholders.
   Click each part to assign it to a carton.
[Cancel] [Create placeholders]
```

The split action only reserves a `multi_box_set_id` (ULID) and the part labels — no `carton_contents` rows are created until each part is actually placed in a carton via the "choose box" action on the placeholder row.

### Errors prevented by structure

| Error class | How the design prevents it |
|---|---|
| Duplicate box label | Auto-generated, unique per shipment |
| Mistyped box label | Operator never types it |
| Forgotten part of a multi-box set | Product status stays `🔧` in-progress, `packed_complete` doesn't increment |
| Double-counted pieces in multi-box sets | `multi_box_set_id` grouping in `PackingProgressService` |
| Inconsistent carton weight | `SUM(weight_share) == gross_weight` validation when detailed |
| Surplus pieces with no destination | Status `❗` and visible counters in the products column |
| Allocating more pieces than remaining | "Pieces" input clamped to `remaining` in the Add Content modal |

## Backend Architecture

Following the project's DDD layout:

```
app/Domain/Logistics/
├── Models/
│   ├── Carton.php                              (NEW)
│   ├── CartonContent.php                       (NEW)
│   └── PackingListItem.php                     (DEPRECATED)
├── Actions/
│   ├── CreateCartonAction.php                  (NEW)
│   ├── AddContentToCartonAction.php            (NEW)
│   ├── SplitProductAcrossCartonsAction.php     (NEW)
│   ├── DeleteCartonAction.php                  (NEW)
│   ├── DeleteCartonContentAction.php           (NEW)
│   ├── UpdateCartonAction.php                  (NEW)
│   ├── RecalculateShipmentTotalsAction.php     (UPDATE — points at cartons)
│   ├── GeneratePackingListV2Action.php         (NEW — replaces GeneratePackingListAction)
│   └── MigratePackingListItemsToCartonsAction.php (NEW, one-shot)
└── Services/
    └── PackingProgressService.php              (NEW)
```

```
app/Livewire/Logistics/
└── PackingListBuilder.php                      (NEW)

resources/views/livewire/logistics/
├── packing-list-builder.blade.php
└── partials/
    ├── product-row.blade.php
    ├── carton-card.blade.php
    ├── add-content-modal.blade.php
    └── split-product-modal.blade.php
```

### Action contracts

| Action | Input | Returns | Side effects |
|---|---|---|---|
| `CreateCartonAction` | `Shipment $shipment, array $defaults = []` | `Carton` | Generates next `BOX-NNN` label, persists carton |
| `AddContentToCartonAction` | `Carton $carton, ShipmentItem $item, int $pieces, ?string $partLabel, ?string $multiBoxSetId` | `CartonContent` | Validates `pieces ≤ remaining` (via `PackingProgressService`), persists, dispatches `RecalculateShipmentTotalsAction` |
| `SplitProductAcrossCartonsAction` | `ShipmentItem $item, int $pieces, array $partLabels` | `string $multiBoxSetId` | Reserves a ULID and stores pending parts in component state — does **not** create `carton_contents` rows yet |
| `UpdateCartonAction` | `Carton $carton, array $attributes` | `Carton` | Updates weight/dim/container/pallet, validates `SUM(weight_share) == gross_weight` if applicable, dispatches recalc |
| `DeleteCartonAction` | `Carton $carton` | `void` | Cascade deletes contents, dispatches recalc |
| `DeleteCartonContentAction` | `CartonContent $content` | `void` | Removes content, dispatches recalc, frees pieces in the product's remaining counter |

### `PackingProgressService`

The single source of truth for progress calculations. Exposes one main method:

```php
public function forShipment(Shipment $shipment): Collection
// Returns: Collection<int, ShipmentItemProgress>
//   shipment_item_id, total, packed_complete, packed_in_progress,
//   pending_parts (array of {set_id, part_label}), status (enum)
```

The Livewire component, the recalc action, and the PDF template all consume this service. No counting logic lives anywhere else.

### `RecalculateShipmentTotalsAction` — updated

Aggregates from `cartons` instead of `packing_list_items`:

```php
$totals = $shipment->cartons()
    ->selectRaw('
        COUNT(*) as total_packages,
        SUM(gross_weight) as total_gross,
        SUM(net_weight) as total_net,
        SUM(volume) as total_vol
    ')
    ->first();

$shipment->update([
    'total_packages'     => $totals->total_packages,
    'total_gross_weight' => $totals->total_gross,
    'total_net_weight'   => $totals->total_net,
    'total_volume'       => $totals->total_vol,
]);
```

When `cartons` is empty, falls back to `shipment_items` totals (preserves current behavior for shipments without packing data).

### PDF — `PackingListPdfTemplate.php` reworked

The template's `getDocumentData()` is rewritten to consume `cartons` directly. The view (`pdf.packing-list.blade.php`) is left unchanged where possible — only the data shape passed in is updated to match what the view already expects (one row per carton, with one or more product lines per row for mixed cartons).

The legacy `calculateDedupedTotals()` helper is deleted — deduplication is no longer needed because each carton is a real row.

## Data Migration

Migration of existing production data is handled by `MigratePackingListItemsToCartonsAction`, invoked one-shot via an artisan command. Algorithm:

1. For each `Shipment` that has `packing_list_items`:
   1. Group its items by `(carton_from, carton_to)`. Multiple items sharing a range = a mixed carton in the legacy "mixed mode."
   2. For each carton number `n` in `carton_from..carton_to`:
      1. `firstOrCreate` a `Carton` with `label = "BOX-" + str_pad($n, 3, '0')` and the box-level fields (container_number, pallet_number, packaging_type, gross_weight, net_weight, length, width, height, volume).
      2. For each legacy item in this range, create a `CartonContent` with `pieces = qty_per_carton`, `part_label = package_label`, and:
         - `multi_box_set_id = NULL` if the item is standalone (no siblings with the same `shipment_item_id` in this shipment that have `is_primary_package = false`)
         - A synthesized ULID (shared across siblings) if **any** packing_list_item with the same `shipment_item_id` in this shipment has `is_primary_package = false`. All such siblings — primary and non-primary — receive the same ULID. The migration runs a pre-pass per shipment to compute the `shipment_item_id → ULID` map, then applies it during row creation.
2. Validate post-migration: for each shipment, recompute totals from cartons and compare to the previous totals stored on `shipments`. Mismatches abort the migration with a clear error log.

The action is **idempotent** — running twice on the same data is safe (uses `firstOrCreate`).

The legacy table `packing_list_items` is **not** dropped in the same migration. A separate cleanup migration (PR #4) drops it after operations confirms production stability.

## Rollout Plan

Three sequential PRs to dilute risk, plus a final cleanup PR.

| PR | Scope | Safe to deploy alone? |
|---|---|---|
| **#1 — Schema + data migration** | New tables, models, `MigratePackingListItemsToCartonsAction`, artisan command. PDF and UI still use `packing_list_items`. | Yes — additive, no behavior change |
| **#2 — Backend + PDF V2** | New actions, `PackingProgressService`, `RecalculateShipmentTotalsAction` repointed at cartons, `PackingListPdfTemplate` reworked. UI still uses old form, but a model observer double-writes legacy edits to cartons to keep them in sync. | Yes — observable behavior unchanged |
| **#3 — Livewire UI + obsolete cleanup** | `PackingListBuilder` Livewire component replaces `PackingListRelationManager` in `ShipmentResource`. Removes `package_label`/`is_primary_package` fields added by the previous incremental patch. | Yes — data already populated by PR #1 |
| **#4 — Final cleanup** (weeks later) | Drops `packing_list_items` table, removes the double-write observer, removes `MigratePackingListItemsToCartonsAction`, removes `GeneratePackingListAction` legacy. | Yes — only after operations confirms no rollback needed |

## Testing Strategy

### Unit tests

- `tests/Unit/Logistics/PackingProgressServiceTest.php` — exhaustively covers all 4 scenarios + edge cases (zero parts, partially-allocated set, deleted set member, content with NULL `shipment_item_id`).
- `tests/Unit/Logistics/MultiBoxSetCountingTest.php` — verifies that `multi_box_set_id` doesn't double-count and `NULL` sums normally.
- `tests/Unit/Logistics/CartonLabelGeneratorTest.php` — auto-numbering correctness with gaps after deletes.

### Feature tests

- `tests/Feature/Logistics/CreateCartonActionTest.php`
- `tests/Feature/Logistics/AddContentToCartonActionTest.php` — `pieces ≤ remaining`, recalc dispatch, multi-box set handling.
- `tests/Feature/Logistics/SplitProductAcrossCartonsActionTest.php`
- `tests/Feature/Logistics/DeleteCartonActionTest.php` — cascade + remaining freed.
- `tests/Feature/Logistics/MigratePackingListItemsToCartonsActionTest.php` — fixtures for each legacy mode (separate, mixed, multi-box from the previous patch); asserts totals preserved.
- `tests/Feature/Logistics/PackingListBuilderLivewireTest.php` — Livewire component happy path + each error class from the table above.
- `tests/Feature/Logistics/PackingListPdfV2Test.php` — generates PDFs for all 4 scenarios and snapshots totals.

### Regression

Run `php artisan test --filter=Shipment` after each PR. The full suite runs in CI on every push.

## Risks and Mitigations

| Risk | Mitigation |
|---|---|
| Data migration loses information from legacy "mixed cartons" | Detect multiple items with identical `(carton_from, carton_to)` and synthesize a `multi_box_set_id`; fixture tests cover this. |
| Livewire component slow with 100+ cartons | Benchmark with a fixture; paginate or use virtual scrolling if needed. Right column collapsed by default beyond a threshold. |
| PDF V2 layout breaks for clients holding V1 PDFs | Snapshot diff in tests; filename versioning (`PL-SHIP-001-v3.pdf`) allows side-by-side existence. |
| Operator confuses internal "Box" with maritime "Container" | Distinct labels and helper text in the UI; document in user-facing release notes. |
| Recently merged patch (`package_label`/`is_primary_package`) data orphaned | Migration explicitly handles both fields; PR #3 only removes them after PR #1 validation passes in production. |
| Component Livewire state desync on concurrent edits | Optimistic locking via `cartons.updated_at`; conflict displays a "reload" prompt. No websocket sync. |
