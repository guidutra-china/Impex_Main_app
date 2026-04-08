# Company Statement Report — Design

**Date:** 2026-04-09
**Status:** Approved — ready for implementation planning

## 1. Overview

A consolidated report per company (client / supplier / forwarder) that summarizes all business processes related to that company along with a financial block. Designed to be simple and easy to read, with filters applied before generation, HTML preview, and PDF export. Supports multi-language output so the same statement can be sent to international counterparts in their preferred language.

**Use cases covered:**
- **Account statement / reconciliation** — periodic recap of everything done with a counterpart, used as a commercial "bank-style" statement.
- **Operational snapshot** — current status of all in-flight processes filtered by period/status.

**Entry points:**
- **Admin panel** — `GenerateStatementAction` on `CompanyResource` (table row action + edit page header action). Works for any `CompanyRole`.
- **Client portal** — `Statements` page in `PortalPanelProvider` sidebar, scope locked to the logged user's company.
- **Supplier portal** — `Statements` page in `SupplierPortalPanelProvider` sidebar, scope locked to the logged user's company.
- **Forwarders** — no portal today, so access is admin-only for emailing reports out.

**Output flow:** filters modal → HTML preview page → actions `Download PDF`, `Send by Email` (admin only), `Print`.

## 2. Report content by role

| Role | Sections included |
|------|-------------------|
| Client | Inquiries, Quotations, Proforma Invoices, Shipments |
| Supplier | RFQs, Purchase Orders, Shipments |
| Forwarder | Shipments |

Each section is a compact table (hybrid layout — tables by default, with a `detailed mode` option reserved for future iteration). Sections with zero records are omitted from the output.

### 2.1 Columns per section (initial draft)

- **Inquiries**: #, Date, Status, Items count, Project
- **Quotations**: #, Date, Status, Total, Currency, Valid until
- **Proforma Invoices**: #, Date, Status, Total, Paid, Balance, Currency
- **Purchase Orders**: #, Date, Status, Total, Currency, Supplier
- **RFQs**: #, Date, Status, Items, Response deadline
- **Shipments**: #, ETD, ETA, Status, Incoterm, Mode

Numeric columns are right-aligned. Statuses render as plain text (no colored badges) so the PDF and printed versions stay clean.

## 3. Financial summary block

Rendered at the top of the report, right after the company header. Tier: **Intermediate**.

- **Totals by currency** — one row per currency: Invoiced, Paid, Open balance.
- **Aging (open balance)** — buckets 0-30, 31-60, 61-90, 90+ days, per currency.
- **Breakdown by document type** — totals split between Proforma Invoices, Purchase Orders, and other financial documents where applicable.

**Explicitly excluded:**
- Profit margin or internal cost data (never shown in any output — the same template is used in admin and portals, so this is enforced in the builder, not in the view).
- Automatic currency conversion — each currency stays separate. Converting would introduce FX-rate ambiguity and audit risk.
- Detailed payment-transaction history — not part of the "simple" scope.

When the role has no relevant financial context (e.g. forwarder), the financial summary block is omitted entirely.

## 4. Filters (pre-generation modal)

Tier: **Standard**.

| Filter | Notes |
|--------|-------|
| Period | Date range (from / to). Year shortcut helpers allowed. |
| Status | Active / Closed / All. |
| Sections | Checkboxes — pre-checked per role, user may untick. |
| Currency | Show all / filter to a specific currency. No conversion. |
| Language | Dropdown of locales detected from `lang/` (en, pt_BR, zh_CN). Default: `$company->preferred_language ?? auth()->user()->locale ?? config('app.locale')`. |

In the portal the Company selector is absent — the page is scoped to the logged user's company.

## 5. Layout sketch

```
┌─────────────────────────────────────────────────────┐
│  [LOGO]                          STATEMENT          │
│                                  Period: 2025-01 → 2026-04 │
│                                  Generated: 2026-04-09    │
├─────────────────────────────────────────────────────┤
│  COMPANY                                            │
│  ACME Importers Ltd.                                │
│  São Paulo, Brazil · contact@acme.com               │
├─────────────────────────────────────────────────────┤
│  FINANCIAL SUMMARY                                  │
│                                                     │
│  Totals by currency                                 │
│    USD   Invoiced 125,000  Paid 98,000  Open 27,000│
│    EUR    Invoiced  12,500  Paid 12,500  Open      0│
│                                                     │
│  Aging (open balance, USD)                          │
│    0-30 days    15,000                              │
│    31-60 days    8,000                              │
│    61-90 days    4,000                              │
│    90+ days          0                              │
│                                                     │
│  Breakdown by document type (USD)                   │
│    Proforma Invoices   120,000                      │
│    Other                 5,000                      │
├─────────────────────────────────────────────────────┤
│  INQUIRIES (12)                                     │
│  #         Date        Status      Items   Project  │
│  ...                                                 │
├─────────────────────────────────────────────────────┤
│  QUOTATIONS (8) ...                                 │
│  PROFORMA INVOICES (6) ...                          │
│  SHIPMENTS (4) ...                                  │
└─────────────────────────────────────────────────────┘
```

Header is compact: company block + period + generation date. Each section shows its title and the record count; empty sections are hidden.

## 6. Architecture

### 6.1 Domain layer — `app/Domain/CRM/Reports/`

- **`CompanyStatementService`** — orchestrator. `build(Company $company, StatementFilters $filters): StatementReport`.
- **`StatementFilters`** (DTO) — period (from/to), status, section keys, currency, locale.
- **`StatementReport`** (DTO) — `header`, `financialSummary` (nullable), `sections[]`. Each section: title key, columns, rows.
- **`SectionBuilders/`** — one builder per entity: `InquirySectionBuilder`, `QuotationSectionBuilder`, `ProformaInvoiceSectionBuilder`, `ShipmentSectionBuilder`, `PurchaseOrderSectionBuilder`, `RfqSectionBuilder`. Each knows its columns and how to query filtered rows for a given company.
- **`FinancialSummaryBuilder`** — computes currency totals, aging buckets, document-type breakdown. Shared calculation logic previously duplicated in `Client360DataService` is extracted here; `Client360DataService` becomes a consumer, avoiding drift.
- **`StatementSectionResolver`** — maps `CompanyRole` → ordered list of section builders. Single source of truth for "who sees what".

The domain layer has no knowledge of Filament, HTTP, or Blade — it is purely testable via factories.

### 6.2 Presentation layer

- **`StatementPreviewPage`** (Filament Page) — reused by admin and both portals. A scope guard resolves the target company (from query param in admin; from the logged user's company in portals).
- **Blade view `resources/views/statements/preview.blade.php`** — renders the HTML preview. The same view is used by the PDF template to guarantee parity.
- **`CompanyStatementPdfTemplate`** — extends `AbstractPdfTemplate`, renders the shared Blade view into PDF.

### 6.3 Entry points

- **Admin** — `GenerateStatementAction` attached to `CompanyResource` as both a table row action and an edit page header action. Opens the filters modal, submits, redirects to `StatementPreviewPage` with the filter payload.
- **Client portal** — `StatementsPage` registered in `PortalPanelProvider`, inline filter form, same preview below.
- **Supplier portal** — `StatementsPage` registered in `SupplierPortalPanelProvider`, same pattern as client portal.

### 6.4 Authorization

- **`StatementPolicy::view(User $user, Company $company)`**
  - Admin panel users: always allowed.
  - Portal users: allowed only when `$company->id === $user->company_id` (or equivalent relationship). Enforced at page-mount time and re-checked before PDF render / email send.

## 7. Multi-language support

**Translated content:**
1. Static labels (report title, section titles, column headers, financial block labels) — via new translation file `lang/<locale>/statements.php`, filled for **en**, **pt_BR**, and **zh_CN**.
2. Status enums — already implemented via Filament's `HasLabel` contract reading from `lang/enums.php`; no extra work.

**Not translated:** free-text domain data (product names, notes, item descriptions) stays in whatever language it was captured in.

**Language selection:**
- New migration adds `companies.preferred_language` (nullable string, default null).
- Filters modal dropdown defaults to `$company->preferred_language ?? auth()->user()->locale ?? config('app.locale')`, but the user may override per-report.

**Locale application during render:**
- `CompanyStatementService` receives the locale from `StatementFilters`.
- Before rendering the Blade view (or PDF), the service wraps the render in `App::setLocale($locale)` inside a `try/finally` block so the previous locale is restored — the locale switch must not leak into the rest of the HTTP request.

**Risks:**
- **PDF font coverage for Chinese glyphs** — `AbstractPdfTemplate` must be validated during implementation. If the current font stack cannot render `zh_CN`, add a CJK-capable font before shipping the feature. Documented as a known implementation checkpoint.

## 8. Data flow

```
Admin user → "Generate Statement" on CompanyResource
  → filters modal (period, status, sections, currency, language)
  → submit → redirect to StatementPreviewPage
    → CompanyStatementService->build(Company, StatementFilters)
      → StatementSectionResolver picks builders for the role
      → each SectionBuilder runs a filtered query (period, status, company_id)
      → FinancialSummaryBuilder aggregates
      → returns StatementReport DTO
    → page renders Blade view under the selected locale
  → user picks: Download PDF | Send by Email (admin) | Print

Portal user → "Statements" sidebar entry
  → StatementsPage (company pinned to logged user's company)
  → inline filter form
  → same pipeline from CompanyStatementService onward
```

## 9. Error handling & edge cases

- **Empty period** — the preview renders the header and a single "No records found for the selected filters" notice. No empty section tables are displayed.
- **Multiple currencies** — the financial summary shows one row per currency. No conversion is performed.
- **Locale not installed** — the filter dropdown only exposes locales found in `lang/`; invalid values fall back to `config('app.fallback_locale')`.
- **Cross-company access in portal** — blocked by `StatementPolicy` with a 403 response.
- **Failed PDF render (e.g. missing CJK font)** — logged, user receives a friendly error, HTML preview stays functional.

## 10. Testing

**Unit tests**
- Each `SectionBuilder` — period/status/company filters, exclusion of records from other companies.
- `FinancialSummaryBuilder` — empty dataset, multi-currency, each aging bucket, breakdown by document type.
- `StatementSectionResolver` — one case per `CompanyRole`.

**Feature tests**
- `CompanyStatementService->build()` — assert DTO shape for a fully populated fake company.
- Authorization — portal user attempting to load a statement for another company gets 403.
- Locale handling — rendering under `pt_BR` or `zh_CN` does not leak the locale into the next request (locale before == locale after).

**Visual smoke tests**
- Generate the PDF in all three languages; assert the process completes without throwing and the binary is non-empty. No pixel-level comparison.

## 11. Out of scope (YAGNI)

- Persistent storage of generated statements (history table). On-demand generation is sufficient; add a `generated_statements` table later if audit trail becomes a requirement.
- Scheduled/automated delivery (monthly emails, cron). Separate feature.
- Excel / CSV export. HTML and PDF only.
- Charts or graphs. Tables and plain numbers only.
- Automatic currency conversion.
- Profit margin or cost information of any kind.

## 12. Implementation checkpoints

1. Extract shared financial calculations from `Client360DataService` into `FinancialSummaryBuilder` without changing `Client360` behavior (regression covered by existing tests).
2. Validate that `AbstractPdfTemplate` can render Chinese glyphs. If not, add a CJK font **before** wiring the language dropdown.
3. Add `companies.preferred_language` migration and Filament form field (separate from the statement feature — can be shipped first).
4. Build `StatementPolicy` and wire it into the Filament pages and PDF/email endpoints.
