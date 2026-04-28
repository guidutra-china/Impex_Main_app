# Quotation FX Snapshot — Design Spec

**Date:** 2026-04-28
**Author:** Gui (brainstormed with Claude)
**Status:** Draft (pending implementation plan)
**Scope:** Domains `Inquiries`, `SupplierQuotations`, `Quotations`. Adjacent: `ProformaInvoices` (resolver shared).

---

## 1. Problem statement

The trade flow `Inquiry → SupplierQuotation → Quotation (to client)` is multi-currency by nature (Inquiry in USD, Supplier Quotation in CNY/EUR/etc., Quotation back to USD), but the codebase **does not perform any FX conversion between SupplierQuotation and Quotation**.

Concretely:
- `app/Filament/Resources/Inquiries/Concerns/InquiryHeaderActions.php:439-519` (`createQuotationAction`) copies `SupplierQuotationItem.unit_cost` directly into `QuotationItem.unit_cost` and computes `unit_price = unit_cost × (1 + commission_rate/100)` regardless of the source currency.
- `QuotationItem.php:83-90`'s `margin` accessor calculates `((unit_price - unit_cost) / unit_cost) × 100`, mixing currencies when they differ.
- The only FX resolution path in the codebase is `app/Domain/ProformaInvoices/Services/ProformaInvoiceItemCurrencyResolver.php`, used **after** the Proforma Invoice stage.

The result: every Quotation that aggregates supplier costs in a non-quote currency has an incorrect `unit_price`, an incorrect `margin`, or both. Internal users can't trust the margin column. The PDF sent to the client may misprice items.

This spec covers fixing the gap by introducing per-item FX snapshots in the Quotation domain, mirroring the pattern already used in `ProformaInvoiceItem`.

## 2. Out of scope

- Inquiry-to-PI FX resolution (already correct, untouched).
- Renaming or removing the Quotation entity. Quotation remains as a snapshot-with-versioning artifact, not a thin report.
- Allowing Quotation creation outside an Inquiry — that's a separate question for a future spec.
- Live/automatic FX rate ingestion. We use whatever `ExchangeRate` rows already exist; the SQ/Quotation flow doesn't fetch new rates from the network.
- Tightening `cost_currency_code` / `cost_exchange_rate` to `NOT NULL` after backfill. That's a follow-up phase once we've validated the backfill output.

## 3. Architecture overview

```
Inquiry (USD)
  ├── SupplierQuotation A (CNY)         ──┐
  │     └── SupplierQuotationItem (CNY)   │   each SQ item carries its source currency
  ├── SupplierQuotation B (EUR)         ──┤   (inherited from SQ header)
  │     └── SupplierQuotationItem (EUR)   │
  └── Quotation (USD)                   ──┘
        ├── QuotationItem (USD price, CNY cost + rate snapshot)
        │     └── QuotationItemSupplier × N (alternatives, each with own rate snapshot)
        └── QuotationVersion (JSON snapshot, audit)

FX resolution: shared CurrencyExchangeResolver service
  ├── ProformaInvoiceItem (already uses it via ProformaInvoiceItemCurrencyResolver)
  └── QuotationItem        (NEW)
```

**Design principles:**
- **Snapshot per item** (not header). One Quotation can mix items sourced from CNY, EUR, and USD SQs simultaneously.
- **Frozen on creation, refreshable in DRAFT, locked at SENT+.** Once a Quotation is SENT/NEGOTIATING, the only way to recompute is creating a new version (existing `QuotationVersion` mechanism).
- **No cascade outside the action.** Editing a rate in the Filament edit form does not automatically recompute `unit_price`. Cascade only happens when `CreateOrUpdateQuotationFromInquiryAction` runs.
- **Multi-supplier alternatives become first-class.** `QuotationItemSupplier` (currently dormant) is populated by the action with all alternatives from selected SQs and is exposed in the UI.
- **Client PDF is unchanged.** Customers never see source costs, rates, or source currency — they see prices in the Quotation currency, identical to today.

## 4. Schema changes

### 4.1 `quotation_items`
Migration: `add_fx_snapshot_to_quotation_items`
```
+ cost_currency_code  CHAR(3)        NULL
+ cost_exchange_rate  DECIMAL(18,8)  NULL
```
Both nullable on add. NULL = legacy row (pre-backfill). Code treats NULL as `cost_currency_code = quotation.currency_code` and `rate = 1.0`.

### 4.2 `quotation_item_suppliers`
Migration: `add_fx_snapshot_to_quotation_item_suppliers`
```
+ cost_exchange_rate  DECIMAL(18,8)  NULL
```
`currency_code` already exists on this table. The unique constraint `(quotation_item_id, company_id)` is unchanged.

### 4.3 What does NOT change
- `inquiries`, `inquiry_items` — Inquiry stays single-currency at the header (`currency_code`); Inquiry never holds cost data.
- `supplier_quotations`, `supplier_quotation_items` — SQ stays in supplier currency at the header; no FX snapshot needed here because SQ is the source of truth.
- `quotations` (header) — already has `currency_code`. No new field needed.
- `exchange_rates` — already has `DECIMAL(18,8)` precision and the lookup logic in `ExchangeRate::convert`. Reused as-is.

## 5. Models & accessors

### 5.1 `app/Domain/Quotations/Models/QuotationItem.php`

Add to `$fillable`: `cost_currency_code`, `cost_exchange_rate`.
Add to `$casts`: `cost_exchange_rate => 'decimal:8'`.

New accessors:
```php
public function getConvertedUnitCostAttribute(): int
{
    if ($this->cost_currency_code === null
        || $this->cost_currency_code === $this->quotation->currency_code) {
        return $this->unit_cost;
    }
    return (int) round($this->unit_cost * (float) $this->cost_exchange_rate);
}

public function getConvertedCostTotalAttribute(): int
{
    return $this->converted_unit_cost * $this->quantity;
}
```

Refactored accessor (existing `getMarginAttribute`):
```php
public function getMarginAttribute(): float
{
    $cost = $this->converted_unit_cost;
    if ($cost <= 0) return 0;
    return round((($this->unit_price - $cost) / $cost) * 100, 2);
}
```

The existing `getCostTotalAttribute()` (which returns `unit_cost × quantity` in the source currency) is preserved unchanged so existing consumers don't break. Code that needs the converted value uses the new accessor explicitly.

### 5.2 `app/Domain/Quotations/Models/QuotationItemSupplier.php`

Add to `$fillable`: `cost_exchange_rate`.
Add to `$casts`: `cost_exchange_rate => 'decimal:8'`.
Add same `converted_unit_cost` and `converted_cost_total` accessors (resolved against parent QuotationItem's quotation currency).

### 5.3 `app/Domain/Quotations/Models/Quotation.php`

No structural change. `getSubtotalAttribute()` continues to sum `line_total` (which uses `unit_price × quantity`, always in quote currency), so it remains correct.

New accessor:
```php
public function getTotalConvertedCostAttribute(): int
{
    return $this->items->sum('converted_cost_total');
}
```

Useful for the new "FX Summary" infolist block and for an aggregated margin display.

## 6. Domain action

### 6.1 New file: `app/Domain/Quotations/Actions/CreateOrUpdateQuotationFromInquiryAction.php`

Replaces the inline closure logic in `InquiryHeaderActions.php:305-556`. The Filament action becomes a thin shell that builds the input payload and calls `$action->execute(...)`.

```php
class CreateOrUpdateQuotationFromInquiryAction
{
    public function __construct(
        private CurrencyExchangeResolver $fx,
    ) {}

    public function execute(
        Inquiry $inquiry,
        array $supplierQuotationIds,
        QuotationCommissionType $commissionType,
        float $commissionRate,
        bool $showSuppliers,
        bool $forceNewVersion = false,
    ): Quotation;
}
```

### 6.2 New file: `app/Domain/Settings/Services/CurrencyExchangeResolver.php`

Extracted from `ProformaInvoiceItemCurrencyResolver`. Both PI and Quotation depend on it; the PI-specific class becomes a thin wrapper that delegates here.

```php
class CurrencyExchangeResolver
{
    public function resolve(
        ?string $sourceCurrency,
        string $targetCurrency,
        ?string $date = null,
    ): array {
        // returns ['currency' => string, 'rate' => float]
        // throws CurrencyExchangeRateUnavailable when no rate found and source !== target
    }
}
```

### 6.3 Action algorithm

1. **Resolve target Quotation:**
   - Find active quotation (DRAFT/SENT/NEGOTIATING) for this inquiry.
   - If `forceNewVersion=true` OR target is SENT/NEGOTIATING: snapshot current state into `QuotationVersion`, increment `version`, treat going forward as a fresh draft (status = DRAFT).
   - If DRAFT: update in-place.
   - If none: create.

2. **Lock check:**
   - If target is SENT/NEGOTIATING and `forceNewVersion=false`, throw `QuotationLockedException` with message: "Quotation já enviada, recálculo bloqueado. Use 'Nova versão'."
   - The Filament action catches this and shows a button "Criar nova versão" instead of failing silently.

3. **For each `InquiryItem`:**

   a. Find all `SupplierQuotationItem`s across the selected SQs that match by `product_id`. This is the alternatives pool for `QuotationItemSupplier`.

   b. Elect primary source: the alternative with the **lowest converted cost** (resolved via `CurrencyExchangeResolver`). Tiebreak: first found.

   c. Resolve FX for the primary source:
      ```php
      $resolved = $this->fx->resolve(
          $primarySq->currency_code,
          $quotation->currency_code,
          $quotation->issue_date?->toDateString() ?? today()->toDateString(),
      );
      ```

   d. **Upsert `QuotationItem`** (match by `inquiry_item_id`):
      - `unit_cost` ← refreshed from primary `SupplierQuotationItem.unit_cost`
      - `cost_currency_code` ← `$resolved['currency']`
      - `cost_exchange_rate` ← `$resolved['rate']`
      - `unit_price` recalculated:
        - EMBEDDED: `(unit_cost × rate) × (1 + commission_rate/100)`
        - SEPARATE: `unit_cost × rate` (commission added at quotation total level)
      - `commission_rate` **preserved** if item already existed and had an override; otherwise inherits from header.
      - Items that previously matched but no longer have a source SQ (because user unchecked it): retained as-is, flagged in the action's return summary so the UI can warn.

   e. **Sync `QuotationItemSupplier` alternatives:**
      - For each alternative `SupplierQuotationItem` in the pool:
        - Resolve FX using its own currency.
        - Upsert `QuotationItemSupplier` (unique by `(quotation_item_id, company_id)`).
        - Fields: `unit_cost`, `currency_code`, `cost_exchange_rate`, `lead_time_days`, `moq`, `incoterm`, `notes`.
      - Alternatives that no longer appear in the selected SQs: deleted (history is preserved by `QuotationVersion.snapshot`).

4. **Persist** inside `DB::transaction`. If a new version was created, write `QuotationVersion` with full pre-change snapshot.

5. **Inquiry status:** if Inquiry was RECEIVED, transition to QUOTING (preserves existing behavior).

### 6.4 Refresh semantics summary

| State of target | Re-running action does | Lockable |
|---|---|---|
| No active quotation | Creates new, version=1 | n/a |
| DRAFT | Refreshes `unit_cost`, `cost_currency_code`, `cost_exchange_rate`, recalculates `unit_price`. Preserves `commission_rate` overrides. | n/a |
| SENT / NEGOTIATING | Throws `QuotationLockedException` unless `forceNewVersion=true`. With force: snapshots current to `QuotationVersion`, creates version+1, treats as fresh DRAFT. | yes |
| APPROVED / REJECTED / EXPIRED / CANCELLED | Throws (terminal states). | yes |

## 7. Filament form behavior

### 7.1 Form layout

Form for the action `Create or update quotation` invoked from the Inquiry header.

**Header section** (existing fields, kept):
- `supplier_quotation_ids` — `CheckboxList` of available SQs for this Inquiry
- `commission_type` — `Select` (EMBEDDED / SEPARATE)
- `commission_rate` — `TextInput` numeric
- `show_suppliers` — `Toggle`

**Items preview section** — `Repeater` reactive on `supplier_quotation_ids`. One row per `InquiryItem`:

| Column | Behavior |
|---|---|
| Product | Read-only label, pre-populated from InquiryItem |
| Quantity | `TextInput` numeric, editable, defaulted from InquiryItem |
| Source SQ | Read-only label: "SQ-2026-0034 · Supplier ABC" |
| Source unit cost | `TextInput` numeric, editable (raw in source currency) |
| Cost currency | `Select` (CNY/USD/EUR/...), editable |
| Cost FX rate | `TextInput` numeric, **auto-prefilled by `CurrencyExchangeResolver`**, editable |
| Converted cost | Live label: `unit_cost × rate` in quote currency |
| Commission % | `TextInput` numeric, inherits from header on first render, editable per row |
| Unit price | `TextInput` numeric, auto-calculated on initial population, **not cascaded thereafter** |
| Margin | Live label: `((price - converted_cost) / converted_cost) × 100` |

### 7.2 Reactivity

- Toggling SQs in the header → `afterStateUpdated` runs `previewItems()` (action steps 3a-3d-without-persist) and re-populates the repeater.
- Editing any field within a row → only the live labels (`Converted cost`, `Margin`) recompute. `unit_price` is **not touched**.
- Per-row "Recalculate price" button — explicit opt-in if the user wants to re-cascade.

### 7.3 Visual lock

- DRAFT target: form fully editable.
- SENT/NEGOTIATING target: form rendered read-only with a banner: "Esta cotação já foi enviada. Para mexer, crie uma nova versão." plus a button `Criar nova versão` that re-opens the form with `forceNewVersion=true`.

### 7.4 Validation

- `cost_exchange_rate > 0` required when `cost_currency_code !== quotation.currency_code`.
- `quantity > 0`, `unit_price >= 0`.
- If `cost_currency_code === quotation.currency_code` and rate provided ≠ 1.0: warning (not blocking) — likely user error.

### 7.5 Multi-supplier comparison block

Within each repeater row, a collapsed sub-block lists the alternatives gathered from selected SQs (read-only at create time). Editing happens on the Quotation edit page (Section 8.3).

## 8. UI updates beyond the create form

### 8.1 `QuotationsTable` (`app/Filament/Resources/Quotations/Tables/`)

- Existing `total` column: kept.
- New `total_cost` column: sum of `converted_cost_total` across items.
- New `avg_margin` column: `((subtotal - total_cost) / total_cost) × 100`. Color-coded: red < 10%, yellow 10-25%, green > 25%.
- New filter `has_multi_currency_items`: quotations with at least one item where `cost_currency_code !== currency_code`.

### 8.2 `QuotationInfolist`

New top-level "FX Summary" block:
```
Quote currency: USD
Items by source currency:
  • CNY → USD @ 0.1395  (12 items, 12,500 CNY = 1,743.75 USD)
  • EUR → USD @ 1.0820  (3 items,  840 EUR = 909.00 USD)
Total cost converted: 2,652.75 USD
Total revenue:        4,200.00 USD
Margin:               58.4%
```

### 8.3 Multi-supplier comparison on Quotation edit/infolist

Layout per QuotationItem:
```
Item: Widget A — selected: Supplier Beta @ 9.50 USD/un (CNY 68 × 0.1395)

Alternatives:
  Supplier         Cost   Curr   Rate    Converted USD   Lead
  ✓ Supplier Beta    68    CNY   .1395    9.50           30d   ← selected
    Supplier Gamma   71    CNY   .1395    9.91           25d
    Supplier Delta    8.20 EUR   1.0820   8.87           45d   ← cheaper
  [Make this the selected supplier]
```

Action "Make this selected" on each alternative row:
- Updates `QuotationItem.selected_supplier_id` to the alternative's `company_id`.
- Copies `unit_cost`, `cost_currency_code`, `cost_exchange_rate` from the alternative to the QuotationItem.
- Recalculates `unit_price` using current `commission_rate`.
- Allowed only when Quotation is in DRAFT.

### 8.4 Adjacent infolists

- `InquiryInfolist`: small block "Latest quotation: Q-2026-0234 v3 — Margin 42% — 3 versions sent". Links to the quotation.
- `SupplierQuotationInfolist`: reverse pointer "Used in quotations: Q-2026-0234 (v2), Q-2026-0241 (v1)" so suppliers' usage is visible.

## 9. PDF behavior

### 9.1 Client-facing PDF (`QuotationPdfTemplate`, `quotation.blade.php`)

**No visible change for clients.** PDF continues to show:
- `unit_price`, `quantity`, `line_total` per item (all in `quotation.currency_code`)
- `subtotal`, `commission` (when SEPARATE), `total`
- Optional supplier name per line (when `show_suppliers=true`)

Source currency, source cost, FX rate, and margin are never rendered on the client PDF.

Each version of the quotation generates its own PDF reflecting the snapshot from `QuotationVersion`, preserving the audit trail.

### 9.2 Internal cost report PDF (optional, post-MVP)

`QuotationInternalCostReportPdfTemplate` — a parallel template restricted to admin panel users, exposing source cost, rate, converted cost, and margin per line. Triggered by a header action "Print internal cost sheet" on the Quotation. Reuses most of the existing template via Blade `@if($internal)`.

Out of MVP. Build only if Gui requests it after PR 5 ships.

## 10. Backfill of historical data

### 10.1 Command

`app/Console/Commands/BackfillQuotationFxSnapshotsCommand.php`

```bash
php artisan quotations:backfill-fx-snapshots [--dry-run] [--quotation=ID] [--report=PATH]
```

### 10.2 Algorithm

For each `QuotationItem` where `cost_currency_code IS NULL`:

1. If `supplier_quotation_item_id` is set → load source SQ, read `currency_code`. Otherwise → use `quotation.currency_code` and rate=1.0; log under `legacy_no_source`.
2. Resolve rate via `ExchangeRate::getLatestRate(source_currency, quote_currency, date)`:
   - First try with `quotation.created_at`.
   - Fallback to source SQ's `created_at`.
   - Still null → rate=1.0; log under `missing_fx_rate`.
3. Persist `cost_currency_code` and `cost_exchange_rate`. **Do not touch** `unit_cost`, `unit_price`, or `commission_rate` — historical margins remain as they were sent to clients.

Repeat the same loop for `quotation_item_suppliers` (which already has `currency_code`, just needs `cost_exchange_rate`).

### 10.3 Output

```
Quotation items processed: 1,247
  ✓ Resolved from SQ source:        1,089
  ⚠ Legacy (no source SQ):              98  → assumed quote currency, rate=1.0
  ⚠ Missing FX rate at quote date:     60  → rate=1.0 (CHECK MANUAL)

Quotation item suppliers processed: 312
  ✓ Resolved:                          304
  ⚠ Missing FX rate:                     8

Run with --dry-run to preview without writing.
Run with --report=path/to/file.csv to dump per-row decisions.
```

### 10.4 Idempotency

Command only touches rows where `cost_currency_code IS NULL` (and the equivalent for the suppliers table). Re-running is safe and a no-op once everything is filled.

## 11. Tests

### 11.1 Unit (`tests/Unit/Domain/Quotations/`)
- `CreateOrUpdateQuotationFromInquiryActionTest`:
  - single-currency flow (USD all the way) → rate=1
  - cross-currency flow (CNY SQ → USD Quotation) → rate≠1, margin correct
  - multi-currency aggregation (CNY + EUR feeding USD quotation) → each item with own rate snapshot
  - re-run in DRAFT → refresh rate + unit_cost, preserve commission_rate overrides
  - re-run in SENT without `forceNewVersion` → throws `QuotationLockedException`
  - re-run in SENT with `forceNewVersion=true` → snapshots and creates new version
  - multi-supplier population → all alternatives become `QuotationItemSupplier` rows
- `QuotationItemMarginTest` → cross-currency margin accessor returns expected percentage
- `CurrencyExchangeResolverTest` → wrapper resolves correctly; missing rate raises `CurrencyExchangeRateUnavailable`

### 11.2 Feature (`tests/Feature/Filament/`)
- `CreateQuotationActionFormTest` → form payload submission triggers domain action with correct args
- `QuotationLockTest` → SENT quotation renders read-only form with "Nova versão" button
- `MultiSupplierComparisonTest` → "Make this selected" action swaps `selected_supplier_id` and rate fields

### 11.3 Console (`tests/Feature/Console/`)
- `BackfillQuotationFxSnapshotsCommandTest` → dry-run vs apply paths, all three buckets exercised (resolved / legacy / missing FX), idempotency verified

## 12. Delivery plan

PRs are ordered by dependency. Each is independently revertible.

1. **PR 1 — Schema + shared resolver**
   - Migrations from Section 4
   - Extract `CurrencyExchangeResolver` to `app/Domain/Settings/Services/`
   - Update `ProformaInvoiceItemCurrencyResolver` to delegate to it
   - No behavior change yet
   - Tests: resolver unit tests
2. **PR 2 — Domain action + model accessors**
   - `CreateOrUpdateQuotationFromInquiryAction`
   - New accessors on `QuotationItem`, `QuotationItemSupplier`, `Quotation`
   - `InquiryHeaderActions::createQuotationAction` delegates to the new action
   - Closure footprint shrinks to ~30 lines (form payload → action)
   - UI form unchanged in this PR; behavior already FX-correct on creation
   - Tests: full unit suite for the domain action
3. **PR 3 — FX-aware Filament form**
   - Repeater layout from Section 7
   - DRAFT / SENT+ lock with "Nova versão" button
   - Reactive previewItems() helper
   - Tests: feature tests for form submission + lock behavior
4. **PR 4 — Backfill command**
   - Command + CSV report
   - Run `--dry-run` on prod, review, then apply
   - Independent of PR 3; can ship before or after
5. **PR 5 — Multi-supplier UI**
   - `QuotationInfolist` "FX Summary" block (Section 8.2)
   - Alternatives table per item with "Make this selected" action (Section 8.3)
   - `QuotationsTable` columns and filter (Section 8.1)
   - `InquiryInfolist`, `SupplierQuotationInfolist` links (Section 8.4)
6. **PR 6 — Internal cost PDF (optional)**
   - Only if requested after PR 5 ships

## 13. Open questions / risks

- **`unit_cost` semantics shift.** Historically, `quotation_items.unit_cost` was implicitly assumed to be in the quote currency. After this work it's explicitly in the source currency. Any code outside the listed touchpoints that reads `unit_cost` directly may now misinterpret. Mitigation: grep for `unit_cost` across `app/Domain/Quotations/`, `app/Filament/Resources/Quotations/`, and exports/reports during PR 2; document the shift in PR 1.
- **Missing exchange rates at backfill time.** The `missing_fx_rate` bucket gets `rate=1.0` as a placeholder. The CSV report must be reviewed manually before accepting; otherwise margins on those legacy items remain wrong (just not silently — they'll be flagged in the FX Summary as `currency mismatch with rate=1`).
- **Commission preservation on re-run.** Choice 6.2 keeps `commission_rate` overrides across re-runs. If a user wants to "reset everything to defaults", they have to clear overrides manually. Could become a request for a future "Reset to header defaults" button per row.
- **`selected_supplier_id` vs primary alternative.** Currently the action always elects lowest-converted-cost as the primary. If the user later picks a different alternative via "Make this selected", a subsequent re-run of the action would re-elect the lowest. This means re-running the action overrides user choice. Mitigation: the lock at SENT prevents this in production cases. For DRAFT cases, the form should warn before re-electing.

## 14. Glossary

- **FX rate snapshot:** the value of `cost_exchange_rate` frozen at the time `CreateOrUpdateQuotationFromInquiryAction` last ran for the item.
- **Quote currency:** `Quotation.currency_code` — the currency the client sees on the PDF.
- **Source currency:** `cost_currency_code` on the QuotationItem — typically inherited from the originating SupplierQuotation's currency.
- **Converted cost:** `unit_cost × cost_exchange_rate`, expressed in the quote currency.
- **Cascade:** automatic recomputation of `unit_price` when underlying inputs change. In this design, cascade only happens when the action runs, never inside the Filament form.
