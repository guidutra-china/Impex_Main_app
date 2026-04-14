# Accounts Payable Report — Client Portal

**Date:** 2026-04-15
**Context:** Client portal (`app/Filament/Portal`)
**Status:** Design approved, ready for implementation plan

---

## Problem

The existing `FinancialReportPage` in the client portal is a comprehensive report covering Proforma Invoices, Shipments, Payments, Debit Notes, and Additional Costs. Clients need a **simpler, cash-flow–oriented view** focused purely on what they owe and when, to support short-term financial planning (not reconciliation).

## Goal

Deliver a dedicated "Contas a Pagar" (Accounts Payable) page in the client portal that shows payment obligations grouped by due date, with a simple period filter and KPI totals.

## Non-Goals

- Export to PDF/Excel (explicitly out of scope for v1 — may come later)
- Reconciliation with payment history
- Multi-company / consolidated views
- Editing or acting on payments (view-only)

---

## Functional Requirements

### Location & Navigation

- New page: **`Contas a Pagar`** at `app/Filament/Portal/Pages/AccountsPayablePage.php`, slug `accounts-payable`.
- The page is registered under the existing navigation group `__('navigation.groups.finance')`.
- **Also:** `FinancialReportPage` is moved into the same Finance group (currently ungrouped).
- Resulting Finance menu order in the client portal:
  1. Payments (existing `PaymentResource`)
  2. Contas a Pagar (new)
  3. Financial Report (moved)

### Period Filtering

- **Presets** (radio/button group): `Próx. 7 dias`, `Próx. 30 dias`, `Próx. 90 dias`, `Este mês`, `Próximo mês`, `Customizado`.
- **Custom range**: date-from + date-to inputs, shown only when "Customizado" is selected.
- Default preset on page load: `Próx. 30 dias`.

### Scope Toggles

- **Incluir vencidas** (default ON): when ON, overdue open items are shown in a dedicated section at the top regardless of the selected period.
- **Incluir pagos** (default OFF): when ON, items already fully paid within the period are included in the grouped sections (but never in the Overdue section).

### Status Definitions

Open statuses: `PaymentScheduleStatus::PENDING`, `PARTIAL`, `OVERDUE` (and any other non-resolved members — authoritative source is the enum's `isResolved()` method).

- **Overdue** = `due_date < today` AND `remaining_amount > 0` AND status is not resolved.
- **Paid** = `status === PAID` (resolved).

### KPI Cards (top of page)

Three cards, left to right:

1. **Vencido** — total remaining on overdue items. If multi-currency, stack one line per currency.
2. **No Período** — total remaining on items whose `due_date` falls in the selected period (excludes overdue section).
3. **Total a Pagar** — sum of the above. Always shown by currency when >1 currency present.

### Grouping & Table

- **Overdue section** (only if toggle ON and items exist): red/emphasized header `🔴 Vencidas (N itens · total)`, followed by a flat table.
- **Period groups**:
  - If selected period span ≤ 90 days → group by **week** (Mon–Sun), header format `📅 Semana DD-DD/mmm (N itens · total)`.
  - If span > 90 days → group by **month**, header format `📅 mmm YYYY (N itens · total)`.
- **Columns** (same for overdue and period groups):
  `Vencimento | Referência | Descrição | Moeda | Valor | Pago | Saldo | Status`
- **Reference column** links to the originating document using the portal's existing Resource routes (`ProformaInvoiceResource`, `PurchaseOrderResource` if present, `ShipmentResource`).
- **Empty state**: if no items match after filters, show a friendly message ("Nenhuma conta a pagar no período selecionado").

### Data Scope

- Only `PaymentScheduleItem` records belonging to the authenticated user's `Company` (via the polymorphic `schedulable` relationship: PI → Company, PO → PI → Company, Shipment → PI/Shipment buyer).
- Users without a `company_id` receive a 403 (same pattern as `FinancialReportPage::resolveCompany`).

---

## Architecture

### New Files

| Path | Purpose |
|------|---------|
| `app/Filament/Portal/Pages/AccountsPayablePage.php` | Filament page: filter form + view binding |
| `resources/views/filament/portal/pages/accounts-payable.blade.php` | KPIs + grouped table rendering |
| `app/Domain/Financial/Queries/AccountsPayableQuery.php` | Pure query class returning a structured result DTO |
| `app/Domain/Financial/DataTransferObjects/AccountsPayableReport.php` | DTO: overdue items, period groups, currency totals |
| `lang/pt_BR/accounts_payable.php` (+ en, + zh_CN if present) | Translations |

### Modified Files

| Path | Change |
|------|--------|
| `app/Filament/Portal/Pages/FinancialReportPage.php` | Add `getNavigationGroup()` returning `__('navigation.groups.finance')` |

### Separation of Concerns

- `AccountsPayableQuery` is framework-agnostic. Input: `company_id`, `dateFrom`, `dateTo`, `includePaid`, `includeOverdue`. Output: `AccountsPayableReport` DTO already grouped and totaled.
- `AccountsPayablePage` holds Livewire state for filter inputs, resolves the authenticated company, calls the query, passes the DTO to the blade view. **No business logic in the page class.**
- Blade view is presentation only — iterates DTO collections, formats currency, renders links.

### Performance

- Eager-load `schedulable` polymorphic relation when fetching items to avoid N+1 on the Reference column.
- Single query per page render; no per-row database hits.

---

## Testing Strategy

TDD, feature-level (follow existing portal test patterns).

### `AccountsPayableQueryTest` (unit/feature on the query class)

- Filters items by `company_id` — items from other companies are excluded.
- Respects `dateFrom`/`dateTo` bounds for the period groups.
- `includePaid = false` excludes fully-paid items; `= true` includes them.
- `includeOverdue = true` surfaces overdue items regardless of period window; `= false` hides them.
- Groups by week when span ≤ 90 days; by month when > 90 days.
- Totals per group and currency are correct.
- Overdue detection: item with `due_date < today` and `remaining_amount > 0` counts as overdue; fully paid past-due items do not.

### `AccountsPayablePageTest` (Filament/Livewire feature)

- Page loads for an authenticated user with a `company_id`.
- User without `company_id` receives 403.
- User A cannot see payment items belonging to Company B (authorization boundary).
- Preset selection updates results (smoke test on `Próx. 30 dias` and `Customizado`).
- Toggles `Incluir vencidas` / `Incluir pagos` change the rendered data.
- `FinancialReportPage` appears under the Finance navigation group after the change.

---

## Open Questions

None — all decisions captured above.

## Future Work (out of scope)

- PDF / Excel export.
- Cash-flow chart (visual bar per period).
- Push notifications or email digest for upcoming/overdue items.
