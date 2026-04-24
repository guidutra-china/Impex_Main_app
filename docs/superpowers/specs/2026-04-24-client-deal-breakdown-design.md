# Client Deal Breakdown — Design Spec

**Date**: 2026-04-24
**Status**: Draft (awaiting user approval)
**Type**: New feature — on-screen financial report

## 1. Problem

The user needs to see, per client, what was received from the client versus what
was paid to suppliers and shipments — with the PI → PO → Shipment relationship
made explicit. Existing reports (`FinancialOverview`, `AdminFinancialReport`,
`Client360`, `MarginAnalysisSectionBuilder`) show related data but none expose
the end-to-end cash cycle per deal.

## 2. Scope

### In scope

- A standalone Filament page under the Finance navigation group.
- Per-client drill-down into each Proforma Invoice (PI), showing:
  - Money received from the client (allocations to PI schedule items).
  - Money paid to suppliers (allocations to linked POs).
  - Money paid to shipments (allocations to linked shipments: both schedule
    and additional costs), attributed by weight/volume.
- Expandable rows revealing structured sub-tables (Receipts, POs, Shipments).
- Multi-currency support with a presentation currency filter and an original
  currency column shown only when it differs.

### Out of scope

- PDF export. The user explicitly requested on-screen only.
- Cross-client aggregation. Unit of analysis is always one client.
- Editing or allocating payments. Read-only.
- Predictive cash flow / forecasting.

## 3. Decisions made during brainstorming

| Topic | Decision |
|---|---|
| Unit of analysis | Per client → drills into PIs |
| Page location | Standalone page in Finance menu |
| Shipment cost attribution | By weight/volume (with documented fallback cascade) |
| Main layout | Expandable table, one row per PI |
| Expanded row content | Three structured sub-tables (Receipts, POs, Shipments) |
| Currency handling | Convert to presentation currency + show original currency column when it differs |
| "Paid to Shipment" composition | Shipment payment schedule + additional costs consolidated; additional costs shown individually inside each shipment sub-row when expanded |
| `client_reference` display | Shown as secondary line under PI and Shipment references when not empty |

## 4. Architecture

Follows existing DDD layout of the codebase (Domain → Application → Filament UI).

```
app/Domain/Financial/Reports/
├── DealBreakdownReportService.php        (entry point — build(client, filters))
├── DTOs/
│   ├── DealBreakdownFilters.php
│   ├── DealBreakdownReport.php
│   ├── KpiSummary.php
│   ├── DealRow.php
│   ├── PiInfo.php
│   ├── ReceiptsBlock.php
│   ├── ReceiptItem.php
│   ├── PoRow.php
│   ├── ShipmentAttributionRow.php
│   ├── AdditionalCostRow.php
│   └── DealTotals.php
└── Support/
    ├── ShipmentAttributionCalculator.php
    └── FxConverter.php

app/Filament/Pages/
└── ClientDealBreakdown.php                (Livewire Page)

resources/views/filament/pages/
└── client-deal-breakdown.blade.php

resources/views/components/client-deal-breakdown/
├── receipts.blade.php
├── purchase-orders.blade.php
└── shipments.blade.php

tests/Unit/Financial/Reports/
├── ShipmentAttributionCalculatorTest.php
├── FxConverterTest.php
└── DealBreakdownReportServiceTest.php

tests/Feature/Filament/Pages/
└── ClientDealBreakdownPageTest.php

tests/Support/
└── DealScenarioBuilder.php                (fixture helper)
```

Rationale for placing reports under `Financial/Reports/` (not `CRM/Reports/`):
the logic is about cash flow and financial attribution, not the customer
relationship. The existing `CRM/Reports/` covers per-company statement-like
output. The new service has a different shape (per-deal, not per-period
statement) and belongs in the Financial bounded context.

## 5. Page shell & filters

### Page class

`App\Filament\Pages\ClientDealBreakdown` (extends `Filament\Pages\Page`).

- Slug: `client-deal-breakdown`
- Navigation group: `finance` (same as `FinancialOverview`)
- Label: `__('navigation.pages.client_deal_breakdown')` — add keys to
  `lang/{en,pt_BR,zh_CN}/navigation.php`
- Permission gate: `auth()->user()?->can('view-payments')` (reuses the
  existing permission used by `FinancialOverview`)
- View: `filament.pages.client-deal-breakdown`

### Public Livewire state

```php
#[Url(as: 'client')]  public ?int    $clientId = null;
#[Url(as: 'from')]    public ?string $fromDate = null;
#[Url(as: 'to')]      public ?string $toDate = null;
#[Url(as: 'currency')]public ?string $presentationCurrency = null;
#[Url(as: 'statuses')]public array  $statuses = [];     // default set in mount()
public array $expandedDeals = [];                        // PI ids
public array $expandedShipments = [];                    // Shipment ids
```

### Header layout

Three sections stacked:

1. **Client selector** — searchable `<select>` of active root companies (no
   `parent_company_id`); same query pattern as `Client360::getClientOptionsProperty`.
2. **Filter row** — period (from/to), presentation currency, status multi-select.
   Default period: start of current year → today. Default statuses:
   `[SENT, CONFIRMED, FINALIZED, REOPENED]` (excludes DRAFT and CANCELLED).
   Default presentation currency: the most frequent `currency_code` among
   the client's PIs in range; ties broken by most recent `issue_date`;
   ultimate fallback USD.
3. **Four KPI cards** visible only after a client is selected:
   - Total Received (in presentation currency)
   - Total Paid to Suppliers
   - Total Paid to Shipments (schedule + additional costs combined)
   - Consolidated Margin (sum of per-deal margins)

### Empty state

Before a client is selected: a single card reading "Select a client to begin".
After selection with no matching deals: "No operations match the current filters".

## 6. Data model — DTOs

All DTOs are PHP `readonly` classes under
`App\Domain\Financial\Reports\DTOs`. Money is stored as `int` using the
existing `Money::SCALE` convention (same as the rest of the Financial domain).

### `DealBreakdownFilters`

```php
readonly class DealBreakdownFilters {
    public CarbonImmutable $from;
    public CarbonImmutable $to;
    public string $presentationCurrency;       // e.g. "USD"
    public array $statuses;                     // list of ProformaInvoiceStatus
}
```

### `DealBreakdownReport`

```php
readonly class DealBreakdownReport {
    public int $clientId;
    public string $clientName;
    public string $presentationCurrency;
    public DealBreakdownFilters $filters;
    public KpiSummary $kpi;
    /** @var list<DealRow> */ public array $deals;
    /** @var list<string> */ public array $unconvertedCurrencyPairs;   // for ⚠ tooltip
}
```

### `KpiSummary`

```php
readonly class KpiSummary {
    public int $totalReceived;         // in presentation currency
    public int $totalPaidSuppliers;
    public int $totalPaidShipments;
    public int $totalMargin;
    public int $dealCount;
}
```

### `DealRow`

```php
readonly class DealRow {
    public PiInfo $pi;
    public ReceiptsBlock $receipts;
    /** @var list<PoRow> */ public array $purchaseOrders;
    /** @var list<ShipmentAttributionRow> */ public array $shipments;
    public DealTotals $totals;
}
```

### `PiInfo`

```php
readonly class PiInfo {
    public int $id;
    public string $reference;
    public ?string $clientReference;           // null/empty → UI hides it
    public CarbonImmutable $issueDate;
    public ProformaInvoiceStatus $status;
    public string $currencyOriginal;
    public int $totalOriginal;
    public ?int $totalPresentation;            // null if FX missing
    public string $detailUrl;                  // ProformaInvoiceResource::getUrl
}
```

### `ReceiptsBlock`

```php
readonly class ReceiptsBlock {
    public int $paidOriginal;
    public ?int $paidPresentation;
    public int $outstandingOriginal;
    public ?int $outstandingPresentation;
    public float $percentPaid;                 // 0-100
    /** @var list<ReceiptItem> */ public array $items;
}
```

### `ReceiptItem`

```php
readonly class ReceiptItem {
    public CarbonImmutable $paymentDate;
    public string $paymentReference;
    public ?string $stageLabel;                // e.g. "1st installment (30%)"
    public int $amountOriginal;
    public ?int $amountPresentation;
    public float $exchangeRateToPresentation;  // for display column
    public string $paymentUrl;
}
```

### `PoRow`

```php
readonly class PoRow {
    public int $id;
    public string $reference;
    public string $supplierName;
    public string $currencyOriginal;
    public int $totalOriginal;
    public ?int $totalPresentation;
    public int $paidOriginal;
    public ?int $paidPresentation;
    public int $outstandingOriginal;
    public ?int $outstandingPresentation;
    public PurchaseOrderStatus $status;
    public string $detailUrl;
}
```

### `ShipmentAttributionRow`

```php
readonly class ShipmentAttributionRow {
    public int $id;
    public string $reference;
    public ?string $clientReference;
    public ?string $forwarderName;
    public string $currencyOriginal;
    public int $totalCostOriginal;              // schedule + additional costs
    public float $attributionPct;               // 0..1
    public AttributionBasis $basis;             // enum: weight | volume | quantity | value
    public int $attributedOriginal;
    public ?int $attributedPresentation;
    public int $paidOriginal;                   // already attributed portion
    public ?int $paidPresentation;
    public int $outstandingOriginal;
    public ?int $outstandingPresentation;
    public string $detailUrl;
    /** @var list<AdditionalCostRow> */ public array $additionalCosts;
}
```

### `AdditionalCostRow`

```php
readonly class AdditionalCostRow {
    public string $label;                      // "Insurance", "Customs clearance"
    public AdditionalCostType $type;
    public int $totalOriginal;
    public int $attributedOriginal;
    public ?int $attributedPresentation;
}
```

Note: `AdditionalCostRow` deliberately has no `paid` field. Additional costs
are not individually allocated — payments target `PaymentScheduleItem` rows,
some of which may reference an `AdditionalCost` via their `source` polymorphic
link (for `commission_mode = separate`), but reconstructing a per-cost paid
amount adds complexity without clear value: the shipment's consolidated
`paidOriginal` already covers everything paid toward that shipment.

### `DealTotals`

```php
readonly class DealTotals {
    public int $cashBalance;                   // received - paid suppliers - paid shipments
    public int $margin;                        // PI total - PO total - attributed shipment total
    public float $marginPct;
}
```

### `AttributionBasis` (enum)

`WEIGHT | VOLUME | QUANTITY | VALUE` — describes which cascade rung the
calculator landed on so the UI can show "rateio: peso" / "rateio: qtd
(peso ausente)".

## 7. Service — `DealBreakdownReportService`

Single method:

```php
public function build(
    Company $client,
    DealBreakdownFilters $filters,
): DealBreakdownReport;
```

### Algorithm

1. **Resolve scope** — if `$client` is a matrix (`parent_company_id IS NULL`
   with children), load all descendant `company_id`s. The PI query uses
   `whereIn('company_id', $ids)`. Mirrors `Client360` branch-consolidation
   pattern.

2. **Fetch PIs with eager loads**:

   ```php
   ProformaInvoice::query()
     ->whereIn('company_id', $scopeIds)
     ->whereIn('status', $filters->statuses)
     ->whereBetween('issue_date', [$filters->from, $filters->to])
     ->with([
       'items:id,proforma_invoice_id,quantity,unit_price,currency_code',
       'paymentScheduleItems.allocations.payment:id,payment_date,reference,currency_code',
       'purchaseOrders.paymentScheduleItems.allocations.payment',
       'purchaseOrders.supplierCompany:id,name',
       'items.shipmentItems.shipment.paymentScheduleItems.allocations.payment',
       'items.shipmentItems.shipment.additionalCosts.allocations.payment',
       'items.shipmentItems.shipment.forwarderCompany:id,name',
     ])
     ->orderBy('issue_date', 'desc')
     ->get();
   ```

3. **Prefetch FX rates** — walk all (document currency, date) and (payment
   currency, payment date) pairs, call
   `ExchangeRate::whereIn(...)->get()` once, build an in-memory keyed cache
   passed into `FxConverter`.

4. **For each PI, build `DealRow`**:
   - Build `PiInfo` directly from the model.
   - Build `ReceiptsBlock` by summing `paymentScheduleItems.allocations`.
   - Build each `PoRow` from the eager-loaded `purchaseOrders`.
   - Build `ShipmentAttributionRow[]` by grouping `items.shipmentItems` by
     shipment and delegating to `ShipmentAttributionCalculator`.
   - Compute `DealTotals` last.

5. **Aggregate KPIs** — sum across all `DealRow.totals` in presentation
   currency, skipping values that came back `null` (and collecting those
   currency pairs into `unconvertedCurrencyPairs` for the tooltip).

6. Return `DealBreakdownReport`.

## 8. Shipment attribution

`App\Domain\Financial\Reports\Support\ShipmentAttributionCalculator`.

```php
public function calculate(
    Shipment $shipment,
    ProformaInvoice $pi,
): ShipmentAttribution;   // internal DTO: pct + basis
```

### Cascade

Given the set of `ShipmentItem`s that belong to `$shipment`, partition into
"for this PI" vs "others" based on whether `proforma_invoice_item_id` belongs
to `$pi->items`.

1. If `SUM(shipment.items.total_weight) > 0`:
   `pct = piItemsWeight / shipmentWeight`, `basis = WEIGHT`.
2. Else if `SUM(shipment.items.total_volume) > 0`:
   `pct = piItemsVolume / shipmentVolume`, `basis = VOLUME`.
3. Else if `SUM(shipment.items.quantity) > 0`:
   `pct = piItemsQty / shipmentQty`, `basis = QUANTITY`.
4. Else fall back to value (sum of `quantity × unit_price` from the matched
   `proformaInvoiceItem`), `basis = VALUE`.
5. If even value sums to 0, return `pct = 0, basis = WEIGHT` (so totals
   surface as "0 attributed" rather than NaN).

The UI displays the basis as a small pill next to the percentage — e.g.,
`60% · rateio: peso` or `45% · rateio: qtd (peso ausente)`.

## 9. Currency conversion

`App\Domain\Financial\Reports\Support\FxConverter`.

### Rules

- **Document values** (PI total, PO total, shipment total cost attributed) —
  converted from the document's `currency_code` to presentation currency using
  the approved rate whose `date <=` the document's `issue_date` (latest wins).
  This freezes the document's value at its moment of existence — margins do
  not drift as FX moves.
- **Payments** — use the payment allocation's own `allocated_amount_in_document_currency`
  to reach the document's currency, then convert to presentation currency
  using the rate on the payment's `payment_date`. This preserves cash reality
  at the moment the payment was made.
- **Same currency** — no conversion; returns the input amount.
- **Missing rate** — returns `null`. Callers must propagate `null` up the
  chain (DTOs hold `?int`). The UI renders `null` as "⚠ FX indisponível"
  and the corresponding currency pair is added to
  `DealBreakdownReport.unconvertedCurrencyPairs`.

### API

```php
final class FxConverter {
    public function __construct(
        private string $presentationCurrency,
        private array $ratesCache,   // pre-fetched, keyed by "USD>BRL|2026-03-15"
    ) {}

    public function convertDocument(int $amount, string $from, CarbonImmutable $at): ?int;
    public function convertPayment(PaymentAllocation $allocation): ?int;
}
```

### FX rate pre-fetching

Before constructing the `FxConverter`, the service collects every distinct
`(fromCurrency, date)` pair it will need (PI issue dates, PO issue dates,
shipment issue dates, payment dates), runs a single
`ExchangeRate::where(status, APPROVED)->whereIn(pairs)->get()`, and builds
the cache. Avoids N+1 queries on rate lookups.

## 10. Filament page view

The page uses a **custom Blade view** rather than Filament's `Table`
component because the required expanded row — with three aligned sub-tables
plus secondary shipment expansion — is not expressible with the native
`Tables\Table` API. `Client360` follows the same pattern (custom blade,
Livewire state).

### Main table columns

| # | Column | Notes |
|---|---|---|
| 1 | ▶ / ▼ | Expand toggle |
| 2 | **PI** | `reference` in bold; `client_reference` as small muted secondary line when present; clickable link opens PI resource in new tab |
| 3 | Issue Date | `issue_date` formatted by locale |
| 4 | Status | Colored pill from `ProformaInvoiceStatus` |
| 5 | Total | Presentation currency; "Original" subline when `currency_original !== presentation` |
| 6 | Received | Green when full; partial shows `paid / total` |
| 7 | Paid Suppliers | Sum across all linked POs |
| 8 | Paid Shipments | Sum across attributed shipments (schedule + additional costs) |
| 9 | Cash Balance | Received − Paid Suppliers − Paid Shipments; green if positive |
| 10 | Margin | PI total − PO total − attributed shipment cost; absolute value + % |
| 11 | Currency | Original currency code (hidden globally when all deals share the presentation currency) |

### Expanded row (per PI)

Three sub-tables in order:

1. **↓ Recebimentos** — columns: Payment date, Ref, Stage label, Amount
   (original), Amount (presentation, if different), FX rate used.
2. **↑ Purchase Orders** — columns: PO ref, Supplier, Total (orig/pres), Paid
   (orig/pres), Outstanding, Status.
3. **↑ Shipments** — columns: Shipment ref + client_reference (when present),
   Forwarder, Total cost, % attribution + basis badge, Attributed
   (orig/pres), Paid, Outstanding. Each shipment row is **itself expandable**
   when it carries additional costs — a nested expand reveals the
   `AdditionalCostRow[]` list.

### Formatting

- Amounts via `App\Domain\Infrastructure\Support\Money::format($amount, $currency)`.
- Percentages with one decimal place; no decimals when whole.
- Missing FX: renders "⚠" with tooltip `title="FX rate not available for
  {pair} on {date}"`.
- Links (PI, PO, Shipment, Payment) use existing
  `*Resource::getUrl('view', ['record' => $id])` — `target="_blank"`.

## 11. Edge cases

| Situation | Behavior |
|---|---|
| Client has no PIs in range | "No operations match the current filters" |
| PI with no linked POs | Row renders; POs sub-table: "Nenhum PO vinculado" |
| PI with items not in any shipment | Shipments sub-table: "Nenhum shipment vinculado"; shipment cost = 0 |
| Shipment missing weight and volume | Cascade to quantity or value; UI badge shows basis used |
| Payment allocation missing `allocated_amount_in_document_currency` | Fallback to `allocated_amount × exchange_rate`; if both absent, DTO field is `null` and row is marked "⚠ dados incompletos" |
| No approved ExchangeRate for a pair | Corresponding DTO field is `null`; UI shows "⚠ FX indisponível"; KPIs show `*` and tooltip lists missing pairs |
| PI currency = presentation currency | "Original" column suppressed for that row |
| All PIs share the presentation currency | Entire Original column suppressed globally |
| Matrix client with branches | PI query scopes `company_id IN (matrix_id, ...branch_ids)` — mirrors `Client360` consolidation |
| PI in DRAFT or CANCELLED | Excluded by default status filter; if included manually by user, row renders with reduced opacity |
| `client_reference` empty/null | Secondary line not rendered (same pattern as ClientAccountsPayable) |

## 12. Testing

### Unit — `tests/Unit/Financial/Reports/`

- **`ShipmentAttributionCalculatorTest`**
  - Basic weight-based split (60/40).
  - Single-PI shipment → 100%.
  - Fallback to volume when weight totals to zero.
  - Fallback to quantity when both weight and volume are zero.
  - Fallback to value when quantity is zero.
  - Returns correct `AttributionBasis` enum in each case.
- **`FxConverterTest`**
  - Same-currency shortcut returns input.
  - Picks latest approved rate with `date <=` requested date.
  - Does not consider unapproved rates or future rates.
  - Returns `null` when no approved rate exists.
  - Pre-filled cache avoids DB queries (assert `DB::listen` count = 0 after
    construction).
- **`DealBreakdownReportServiceTest`**
  - Isolated PI (one PO, one exclusive shipment) — numbers match expectations.
  - Shared shipment across two PIs — each PI gets proportional attribution.
  - PI with no POs / no shipments — blocks render as empty arrays.
  - Default status filter is `[SENT, CONFIRMED, FINALIZED, REOPENED]` (excludes DRAFT and CANCELLED).
  - Multi-currency client (USD PI + EUR PI) converts to BRL presentation.
  - KPI totals equal sum of per-deal totals (skipping `null` values).
  - Matrix client includes branch PIs.

### Feature — `tests/Feature/Filament/Pages/`

- **`ClientDealBreakdownPageTest`**
  - Unauthorized user (no `view-payments`) gets 403.
  - Without `?client=`, renders empty state.
  - With `?client=123`, renders table after mount.
  - URL sync: `?client=123&from=2026-01-01&to=2026-03-31&currency=USD` hydrates Livewire state.
  - `toggleDeal(42)` adds/removes id from `$expandedDeals`.
  - Expanded deal renders three sub-tables.
  - Sub-shipment expand reveals `AdditionalCostRow[]`.
  - Matrix company brings branch PIs into the table.

### Fixture helper

`tests/Support/DealScenarioBuilder.php` — fluent API:

```php
DealScenarioBuilder::make()
    ->forClient($acme)
    ->withPi(reference: 'PI-001', currency: 'USD', total: 80_000)
        ->withPo(reference: 'PO-101', supplier: $supplierX, paid: 35_000)
        ->withShipment(ref: 'SHP-9', totalCost: 8_000, weight: 300, myItemsWeight: 180)
    ->build();
```

Keeps tests readable and pushes the boilerplate out of the test body.

### TDD approach

London-school (per CLAUDE.md): mock `ExchangeRate` lookups in unit tests to
control rate resolution; use real database fixtures via factories for the
service's aggregation logic since that's the behavior under test.

## 13. i18n

New translation keys under `lang/{en,pt_BR,zh_CN}/`:

- `navigation.pages.client_deal_breakdown` — menu label
- `client_deal_breakdown.title` — page title
- `client_deal_breakdown.select_client_prompt`
- `client_deal_breakdown.empty_state`
- `client_deal_breakdown.kpi.*` — the four card labels
- `client_deal_breakdown.columns.*` — table column labels
- `client_deal_breakdown.basis.*` — attribution basis labels
- `client_deal_breakdown.fx_unavailable_tooltip`

## 14. Non-goals / deliberately deferred

- **PDF export** — out of scope per user request.
- **Excel/CSV export** — not requested; can be added later by hooking into
  the DTO tree.
- **Saved filter presets** — out of scope; URL-sync already makes filters
  shareable.
- **Background caching** — the service runs synchronously on each page load.
  If performance becomes an issue with large clients, a cache layer around
  `DealBreakdownReportService::build` keyed on (client, filters) can be
  added without changing the DTO shape.

## 15. Out-of-scope clean-ups (observed, not tackled here)

None material. The existing `FinancialSummaryBuilder` and `MarginAnalysisSectionBuilder`
both remain untouched — they serve a different purpose (per-client
statement/PDF report) and will coexist with this new page.
