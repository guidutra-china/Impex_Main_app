# Proforma Invoice — Multi-Currency Cost Tracking

**Date:** 2026-04-08
**Status:** Design — pending review
**Author:** Brainstormed with Claude

---

## Problem

When a Proforma Invoice (PI) is created in one currency (e.g., USD) but its items are sourced from a Supplier Quotation (SQ) in another currency (e.g., CNY), the cost-side numbers are wrong:

- `proforma_invoice_items.unit_cost` is copied verbatim from `supplier_quotation_items.unit_cost`, **in the supplier's currency (CNY)**.
- `proforma_invoice_items.unit_price` is in the PI's currency (USD).
- `ProformaInvoice::getCostTotalAttribute()` sums `unit_cost` across items, treating CNY values as if they were USD.
- `ProformaInvoice::getMarginAttribute()` then computes `(USD total − CNY total) / CNY total` — mathematically meaningless.
- The `ProformaInvoiceStats` widget displays this broken value labeled with the PI currency symbol.

The same bug exists in the portal stats widget, the PDF template, and any report that touches `cost_total` or `margin`.

There is no `currency_code` column on `proforma_invoice_items` today, so the system has no way to know that a stored cost is in a different currency than the PI.

## Goals

1. PI item costs always render correctly in the PI's currency in widgets, infolists, tables, and PDFs.
2. Margin calculations are mathematically correct.
3. The original supplier-currency cost is preserved for audit and supplier reconciliation.
4. The exchange rate used at PI creation time is **frozen** on the item — historical PIs do not drift when FX rates update.
5. Existing items continue to work after migration (backfill is correct for the common case where currencies already match).

## Non-Goals

- Centralizing multi-currency support across every model in the system (Quotations, POs, Shipments). This spec is scoped to Proforma Invoices only. The same pattern can be applied to other models in follow-up phases.
- Live FX revaluation. Rates are snapshot-at-creation and only change when the user explicitly refreshes them on a specific item.
- Adding currency support to documents that don't currently mix currencies.

## Design Approach

**Snapshot the cost currency and the FX rate on each PI item.** This is the standard ERP pattern, and the codebase already uses it for `AdditionalCost` ([app/Filament/Resources/ProformaInvoices/RelationManagers/ItemsRelationManager.php:646-659](../../../app/Filament/Resources/ProformaInvoices/RelationManagers/ItemsRelationManager.php)) — we follow the same convention for consistency.

We store **three new columns** on `proforma_invoice_items`:

| Column | Purpose |
|---|---|
| `cost_currency_code` (string, 3) | The currency the supplier originally quoted in (e.g., `"CNY"`) |
| `cost_exchange_rate` (decimal 18,8) | Frozen FX rate from `cost_currency_code` → PI currency at the moment the item was created or last refreshed |
| `unit_cost_in_document_currency` (bigint, minor units) | Cached `unit_cost * cost_exchange_rate`, recomputed on every save |

The cached column lets the widget, infolist, PDF, and reports keep using `cost_total` / `margin` accessors without per-row currency arithmetic in queries.

## Schema Change

### Migration: `add_cost_currency_to_proforma_invoice_items`

```php
Schema::table('proforma_invoice_items', function (Blueprint $table) {
    $table->string('cost_currency_code', 3)->nullable()->after('unit_cost');
    $table->decimal('cost_exchange_rate', 18, 8)->default(1)->after('cost_currency_code');
    $table->bigInteger('unit_cost_in_document_currency')->default(0)->after('cost_exchange_rate');
});

// Backfill: assume legacy items were already in the PI's currency
DB::statement('
    UPDATE proforma_invoice_items pii
    INNER JOIN proforma_invoices pi ON pi.id = pii.proforma_invoice_id
    SET pii.cost_currency_code = pi.currency_code,
        pii.cost_exchange_rate = 1,
        pii.unit_cost_in_document_currency = pii.unit_cost
');
```

**Backfill rationale:** Items where currencies already matched will be 100% correct. Items where there was a hidden mismatch will continue to display the same (still-wrong) numbers as before — but now the user has UI tools to identify and fix them, and new items will be correct from creation.

## Model Changes

### `ProformaInvoiceItem`

**Fillable:** add `cost_currency_code`, `cost_exchange_rate`, `unit_cost_in_document_currency`.

**Casts:**
```php
'cost_exchange_rate' => 'decimal:8',
'unit_cost_in_document_currency' => 'integer',
```

**`saving` boot hook:** every time the model is saved, recompute the cached column so it can never be stale (covers inline edits via `TextInputColumn` that bypass forms):
```php
static::saving(function (ProformaInvoiceItem $item) {
    $item->unit_cost_in_document_currency = (int) round(
        $item->unit_cost * (float) $item->cost_exchange_rate
    );
});
```

**Accessors (replace existing):**
```php
public function getCostTotalAttribute(): int
{
    return $this->unit_cost_in_document_currency * $this->quantity;
}

public function getMarginAttribute(): float
{
    $cost = $this->unit_cost_in_document_currency;
    if ($cost <= 0) {
        return 0;
    }
    return round((($this->unit_price - $cost) / $cost) * 100, 2);
}
```

`line_total` is unchanged — it has always been correctly in PI currency.

### `ProformaInvoice`

No changes to `getCostTotalAttribute()` or `getMarginAttribute()` — they delegate to the item accessors, which now return correct values automatically.

## New Service

### `app/Domain/ProformaInvoices/Services/ProformaInvoiceItemCurrencyResolver.php`

Centralizes the lookup of the FX rate so all import paths and the form share one implementation. Wraps the existing `ExchangeRate::convert()` infrastructure.

```php
namespace App\Domain\ProformaInvoices\Services;

use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use Illuminate\Support\Facades\Log;

class ProformaInvoiceItemCurrencyResolver
{
    /**
     * Returns ['currency' => string, 'rate' => float].
     * On any failure, falls back to ['currency' => $sourceCurrency, 'rate' => 1.0]
     * and logs a warning so the user can see the issue in the badge UI.
     */
    public function resolve(
        ?string $sourceCurrency,
        string $targetCurrency,
        ?string $date = null
    ): array {
        $sourceCurrency = $sourceCurrency ?: $targetCurrency;

        if ($sourceCurrency === $targetCurrency) {
            return ['currency' => $targetCurrency, 'rate' => 1.0];
        }

        $source = Currency::findByCode($sourceCurrency);
        $target = Currency::findByCode($targetCurrency);

        if (! $source || ! $target) {
            Log::warning('PI cost currency resolver: unknown currency', [
                'source' => $sourceCurrency,
                'target' => $targetCurrency,
            ]);
            return ['currency' => $sourceCurrency, 'rate' => 1.0];
        }

        $converted = ExchangeRate::convert($source->id, $target->id, 1.0, $date);

        if ($converted === null) {
            Log::warning('PI cost currency resolver: no FX rate available', [
                'source' => $sourceCurrency,
                'target' => $targetCurrency,
                'date' => $date,
            ]);
            return ['currency' => $sourceCurrency, 'rate' => 1.0];
        }

        return ['currency' => $sourceCurrency, 'rate' => $converted];
    }
}
```

This is intentionally a thin wrapper. It is the **only** place that knows about `Currency::findByCode()` + `ExchangeRate::convert()` — everything else calls `resolve()`.

## Capture Points (where the FX gets snapshot)

All three import actions in `ItemsRelationManager` and the manual create form must populate the three new fields. The resolver service gets injected at the call site.

### A) `importFromSupplierQuotationsAction` ([ItemsRelationManager.php:362](../../../app/Filament/Resources/ProformaInvoices/RelationManagers/ItemsRelationManager.php))

The action loads `SupplierQuotationItem` with its `supplierQuotation` (which has `currency_code`). Before each `ProformaInvoiceItem::create()`, call:

```php
$resolved = $resolver->resolve(
    $sqItem->supplierQuotation->currency_code,
    $pi->currency_code,
    $pi->issue_date?->toDateString(),
);
```

Then add to the `create` array:
```php
'cost_currency_code' => $resolved['currency'],
'cost_exchange_rate' => $resolved['rate'],
// unit_cost_in_document_currency populated by saving hook
```

The "update existing item" branch (line 443) also needs to refresh `cost_currency_code` and `cost_exchange_rate` when overwriting `unit_cost`.

### B) `importFromQuotationsAction` ([ItemsRelationManager.php:263](../../../app/Filament/Resources/ProformaInvoices/RelationManagers/ItemsRelationManager.php))

`Quotation` has its own `currency_code`. Same pattern — resolve with `$item->quotation->currency_code`.

### C) `importFromInquiryAction` + `fillFromProduct` ([ItemsRelationManager.php:503](../../../app/Filament/Resources/ProformaInvoices/RelationManagers/ItemsRelationManager.php), [:663](../../../app/Filament/Resources/ProformaInvoices/RelationManagers/ItemsRelationManager.php))

Cost comes from the `company_product` pivot. The pivot **already has `currency_code`** ([Company.php:88](../../../app/Domain/CRM/Models/Company.php)). Read `$preferred->pivot->currency_code` and resolve. Fallback to `$pi->currency_code` (rate=1) when the pivot value is null.

### D) Manual create / edit form ([ItemsRelationManager.php:49](../../../app/Filament/Resources/ProformaInvoices/RelationManagers/ItemsRelationManager.php))

Add three new fields to the form:

```php
Select::make('cost_currency_code')
    ->label(__('forms.labels.cost_currency'))
    ->options(fn () => Currency::where('is_active', true)->pluck('code', 'code'))
    ->default(fn () => $this->getOwnerRecord()->currency_code)
    ->live()
    ->afterStateUpdated(fn (Set $set, Get $get) => $this->refreshRate($get, $set))
    ->required(),

TextInput::make('cost_exchange_rate')
    ->label(__('forms.labels.exchange_rate'))
    ->numeric()
    ->step(0.00000001)
    ->default(1)
    ->helperText(__('forms.helpers.cost_currency_to_pi_currency'))
    ->suffixAction(
        Action::make('refreshRate')
            ->icon('heroicon-o-arrow-path')
            ->action(fn (Set $set, Get $get) => $this->refreshRate($get, $set))
    ),

Placeholder::make('unit_cost_in_document_currency_preview')
    ->label(__('forms.labels.cost_in_pi_currency'))
    ->content(fn (Get $get) => /* live computed display */),
```

`refreshRate()` is a small method on the relation manager that calls the resolver service and `$set('cost_exchange_rate', $resolved['rate'])`.

### E) Inline `TextInputColumn` edits ([ItemsRelationManager.php:193](../../../app/Filament/Resources/ProformaInvoices/RelationManagers/ItemsRelationManager.php))

When the user edits `unit_cost` inline, we keep the existing `cost_currency_code` and `cost_exchange_rate`. The model's `saving` hook will recompute `unit_cost_in_document_currency` automatically.

## UI Changes

### Items table

The `cost` column shows a single line in the cost currency, with a tooltip showing the converted-to-PI value when the currencies differ:

```
¥ 100.0000     ← tooltip: ≈ $14.20 USD @ 0.142
```

When the currencies match, the tooltip is omitted. A small currency badge appears next to the value when there is a mismatch, so reviewers can spot multi-currency rows at a glance.

### Header badge on the PI infolist

When **any** item has `cost_currency_code !== pi.currency_code`, show an info badge: *"Multi-currency costs (3 items) — margin uses snapshot FX rates"*. Clicking opens a small modal listing the items, their original costs, and the snapshot rate. The modal includes a "Refresh all rates from FX table" button that re-runs the resolver against today's rates.

### Widget — `ProformaInvoiceStats`

**No code changes.** `$pi->cost_total` and `$pi->margin` now return correct values automatically because the model accessors changed.

The "Cost / Margin" card optionally appends a small "FX" indicator when any underlying item used a non-1 rate. Implementation: pass `$hasMultiCurrency = $pi->items->contains(fn ($i) => $i->cost_exchange_rate != 1)` into the view data.

## Other Affected Files

These call sites use `unit_cost` or `cost_total` directly and must be reviewed/updated to use `unit_cost_in_document_currency` (or to read through the accessor):

- `app/Domain/Infrastructure/Pdf/Templates/ProformaInvoicePdfTemplate.php` — PDF cost columns
- `app/Filament/Portal/Resources/ProformaInvoiceResource/Widgets/PortalProformaInvoiceStats.php` — portal mirror of the stats widget
- `app/Filament/Resources/ProformaInvoices/Schemas/ProformaInvoiceInfolist.php` — any cost summary lines
- Any `PurchaseOrder` flow that copies from PI items (preserving cost currency on the PO is desirable but **out of scope** — leave a `// TODO: multi-currency` marker)

A grep for `unit_cost` and `cost_total` across `app/` is part of the implementation plan to make sure nothing is missed.

## Testing

### Unit tests
- `ProformaInvoiceItem::getCostTotalAttribute` returns `unit_cost_in_document_currency * quantity` regardless of `unit_cost`'s raw value.
- `ProformaInvoiceItem::getMarginAttribute` computes correct margin when `cost_currency_code !== pi.currency_code`.
- `ProformaInvoiceItem` `saving` hook recomputes `unit_cost_in_document_currency` when `unit_cost`, `cost_exchange_rate`, or both change.
- `ProformaInvoiceItemCurrencyResolver::resolve`:
  - Same currency → rate 1.0
  - Cross-currency via base → uses `ExchangeRate::convert`
  - Unknown currency code → fallback to rate 1.0 + log
  - No rate found → fallback to rate 1.0 + log

### Feature tests
- Importing CNY supplier quotation items into a USD PI populates the three new columns correctly.
- Importing a quotation in EUR into a USD PI populates correctly.
- Importing from inquiry with a `company_product.currency_code` of CNY populates correctly.
- The `ProformaInvoiceStats` widget reports `cost_total` and `margin` in the PI currency for a PI with mixed-currency items.
- Editing `unit_cost` inline preserves `cost_currency_code` and refreshes the cached column.

### Backfill test
- Existing items where `pi.currency_code === supplier_currency` (no mismatch) keep the same `cost_total` and `margin` values after migration.

## Rollout & Backwards Compatibility

1. Migration runs the backfill in a single transaction.
2. Old items with hidden mismatches will continue to show the same incorrect-but-stable numbers — they are not silently "fixed" because we have no way to know what the historical rate was. The header badge alerts users to multi-currency PIs and offers a manual refresh.
3. No API or contract changes.
4. The PDF template is regenerated on demand; old PDFs are unchanged.

## Open Questions / Risks

- **Historical rate accuracy**: backfill uses rate=1 for existing rows. Users with old PIs that had hidden mismatches will need to manually refresh those PIs if they want correct historical margins. This is documented and acceptable.
- **Rounding**: `unit_cost_in_document_currency = round(unit_cost * rate)`. With 8-decimal rates and minor-unit costs, accumulated rounding error per item is at most 1 minor unit (1 cent). Tolerable.
- **Decimal places per currency**: `Currency.decimal_places` exists. The cached column stores PI-currency minor units, which is correct because everything else in the PI uses PI-currency minor units. No special handling needed for currencies with non-2 decimal places — `Money::format` already respects them.
