# Financial Report by Company — Design Spec

## Overview

Comprehensive financial report per company, following the same architectural pattern as the existing Statement feature. Provides a complete view of financial obligations: what's been invoiced, paid, and remains outstanding.

Available in 3 contexts with role-based visibility:
- **Admin Panel**: Full financial view including margins, POs, all payments
- **Client Portal**: Client-facing view (PIs, inbound payments, debit notes, shipment costs — no POs, no margins)
- **Supplier Portal**: Supplier-facing view (POs, outbound payments, debit notes — no client PIs)

## Architecture

Mirrors the Statement pattern exactly:

```
Domain/CRM/Reports/
├── CompanyFinancialReportService.php    (orchestrator — like CompanyStatementService)
├── FinancialReportSectionResolver.php   (role → sections — like StatementSectionResolver)
├── FinancialReportSummaryBuilder.php    (executive summary builder)
├── DTOs/
│   ├── FinancialReportFilters.php       (like StatementFilters)
│   └── FinancialReportData.php          (like StatementReport)
└── FinancialSectionBuilders/
    ├── FinancialSectionBuilder.php      (interface)
    ├── PiFinancialSectionBuilder.php    (PI with payment schedule detail)
    ├── PoFinancialSectionBuilder.php    (PO with payment schedule detail)
    ├── PaymentSectionBuilder.php        (payments listing with allocations)
    ├── ShipmentCostSectionBuilder.php   (shipments with associated costs + payment status)
    ├── DebitNoteSectionBuilder.php      (debit notes with status)
    ├── AdditionalCostFinancialSectionBuilder.php (costs breakdown)
    └── MarginAnalysisSectionBuilder.php (admin only — revenue vs cost)

Filament/Pages/
├── FinancialReportPreview.php           (abstract base — like StatementPreview)
├── AdminFinancialReport.php             (admin page)

Filament/Portal/Pages/
├── FinancialReportPage.php              (client portal)

Filament/SupplierPortal/Pages/
├── FinancialReportPage.php              (supplier portal)

resources/views/
├── filament/pages/financial-report-preview.blade.php
├── financial-report/_content.blade.php
└── pdf/financial-report.blade.php
```

## Sections Detail

### 1. Executive Summary (FinancialReportSummaryBuilder)
Same structure as existing FinancialSummaryBuilder but enhanced:
- **Totals by Currency**: invoiced, paid, open
- **Aging**: 0-30, 31-60, 61-90, 90+ days overdue
- **Breakdown**: by document type

Admin sees both revenue (PI) and cost (PO) sides. Client/Supplier sees only their side.

### 2. Proforma Invoices — Financial Detail (PiFinancialSectionBuilder)
Columns: `number`, `client_reference`, `date`, `status`, `payment_term`, `total`, `additional_costs`, `grand_total`, `paid`, `balance`, `currency`

- `total`: PI line items total
- `additional_costs`: client-billable additional costs sum
- `grand_total`: total + additional_costs
- `paid`: sum of approved allocations on payment schedule
- `balance`: grand_total - paid

**Visibility**: Admin + Client Portal

### 3. Purchase Orders — Financial Detail (PoFinancialSectionBuilder)
Columns: `number`, `supplier`, `date`, `status`, `payment_term`, `total`, `paid`, `balance`, `currency`

**Visibility**: Admin + Supplier Portal (NOT client portal)

### 4. Shipments — Costs & Payment Status (ShipmentCostSectionBuilder)
Columns: `number`, `reference`, `etd`, `eta`, `status`, `goods_value`, `freight`, `insurance`, `duty`, `total_costs`, `paid`, `balance`, `currency`

Shows each shipment with its associated costs (freight, insurance, duty from AdditionalCosts) and whether those costs have been paid. The `paid`/`balance` come from checking payment schedule items linked to the shipment.

**Visibility**: Admin + Client Portal + Supplier Portal (filtered by role)

### 5. Payments (PaymentSectionBuilder)
Columns: `reference`, `date`, `direction`, `amount`, `currency`, `method`, `allocated`, `unallocated`, `status`

- Lists all payments for the company
- Admin: both INBOUND and OUTBOUND
- Client: only INBOUND (payments they made)
- Supplier: only OUTBOUND (payments they received)

**Visibility**: All (filtered by direction per role)

### 6. Debit Notes (DebitNoteSectionBuilder)
Columns: `reference`, `proforma_invoice`, `date`, `due_date`, `total`, `status`, `currency`

**Visibility**: Admin + Client Portal

### 7. Additional Costs Breakdown (AdditionalCostFinancialSectionBuilder)
Columns: `document`, `cost_type`, `description`, `amount`, `currency`, `billable_to`, `status`

- Admin: all costs
- Client: only billable_to CLIENT
- Supplier: only their supplier costs

**Visibility**: All (filtered by role)

### 8. Margin Analysis (MarginAnalysisSectionBuilder)
Columns: `pi_reference`, `revenue`, `cost`, `margin_amount`, `margin_pct`, `currency`

Per-PI: revenue (PI total) vs cost (sum of PO costs) vs margin.

**Visibility**: Admin ONLY

## Section Visibility Matrix

| Section | Admin | Client Portal | Supplier Portal |
|---|---|---|---|
| Executive Summary | Full (revenue + cost) | Revenue only | Cost only |
| Proforma Invoices | Yes (with cost/margin) | Yes (no cost/margin) | No |
| Purchase Orders | Yes | **No** | Yes |
| Shipments & Costs | Yes | Yes | Yes (filtered) |
| Payments | All directions | Inbound only | Outbound only |
| Debit Notes | Yes | Yes | No |
| Additional Costs | All | Client-billable only | Supplier costs only |
| Margin Analysis | Yes | **No** | **No** |

## DTOs

### FinancialReportFilters
```php
final class FinancialReportFilters {
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $statusScope,    // 'all', 'active', 'closed'
        public readonly array $sectionKeys,
        public readonly ?string $currency,
        public readonly string $locale,
        public readonly string $context,         // 'admin', 'client', 'supplier'
    ) {}
}
```

The `context` field is the key difference from StatementFilters — it controls per-section visibility and data filtering.

### FinancialReportData
```php
final class FinancialReportData {
    public function __construct(
        public readonly Company $company,
        public readonly CarbonImmutable $periodFrom,
        public readonly CarbonImmutable $periodTo,
        public readonly CarbonImmutable $generatedAt,
        public readonly string $locale,
        public readonly ?FinancialSummary $financialSummary,
        public readonly array $sections,     // list<StatementSection> — reuse existing DTO
    ) {}
}
```

Reuses the existing `StatementSection` DTO since the tabular structure is identical.

## Reused Components

- `StatementSection` DTO (same columns/rows structure)
- `FinancialSummary`, `CurrencyTotals`, `AgingBuckets` DTOs
- `StatusScopeFilter` utility
- `PdfRenderer` for PDF generation
- `pdf.layouts.document` base layout
- `DocumentLabels` for PDF labels
- Gate `view-statements` (reuse same permission — financial report is a subset of statement access)

## Translations

New lang file `financial_report.php` in en, pt_BR, zh_CN with keys for:
- Title, sections, columns specific to financial report
- Reuse `statements.columns.*` where column names overlap

## PDF

Uses `pdf.financial-report.blade.php` extending `pdf.layouts.document`. Same formatting as `pdf.statement.blade.php` — A4 portrait, company branding, sections rendered as tables.

## Access

| Context | URL | Slug |
|---|---|---|
| Admin | `/panel/financial-report?company={id}` | `financial-report` |
| Client Portal | `/portal/financial-report` | `financial-report` |
| Supplier Portal | `/supplier/financial-report` | `financial-report` |

Admin page is not in navigation (accessed from Company view, like Statement). Portal pages appear in sidebar navigation.

## Implementation Order

1. DTOs (FinancialReportFilters, FinancialReportData)
2. Section builders (all 8)
3. Section resolver + Service
4. Lang files
5. Abstract base page + 3 concrete pages
6. Blade views (preview + content partial)
7. PDF template
8. Wire up navigation link from Company view
