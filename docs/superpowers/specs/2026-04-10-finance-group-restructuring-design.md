tem # Finance Group Restructuring - Design Spec

**Date:** 2026-04-10
**Status:** Draft
**Scope:** Phase 1 — AR/AP Split, Debit Notes, Company Expense Approval

---

## Problem Statement

The Finance group has three core issues:

1. **"Payments" mixes concepts:** A single resource handles both client receivables (inbound) and supplier payables (outbound), forcing users to mentally filter by direction. Professional trade finance systems separate these as distinct workflows.

2. **No formal billing for repassed costs:** Additional costs (freight, customs, testing) marked as `billable_to = CLIENT` auto-generate payment schedule items but lack a formal billing document. The client has no visibility into what's being charged. Freight costs on Shipments covering multiple PIs cannot be properly allocated.

3. **Company Expenses lacks approval workflow:** Expenses are created without review gates, making financial controls weak.

---

## Scope

### In Scope (Phase 1)
- Split Payments into Accounts Receivable + Accounts Payable resources
- Introduce Debit Notes for formalizing client-billable costs
- Add approval workflow to Company Expenses
- Update Financial Overview dashboard

### Out of Scope (Phase 2)
- Client portal improvements (cost breakdown, debit notes visibility, self-service uploads)
- Company Expenses budget tracking and department/cost center fields
- Supplier portal changes

---

## 1. Accounts Receivable + Accounts Payable

### Approach

The `Payment` model remains unchanged. Two new Filament resources replace the single `PaymentResource`, each filtering by `direction`.

### New Resources

**AccountsReceivableResource** (`app/Filament/Resources/Finance/AccountsReceivable/`)
- Model: `Payment::class`
- Query scope: `->where('direction', PaymentDirection::INBOUND)`
- Form: `direction` field hidden, pre-set to INBOUND
- Company select: filtered to clients only
- Column labels: "Client" instead of "Company"
- Navigation: label `Accounts Receivable`, icon `heroicon-o-arrow-down-left`, sort 61
- Slug: `accounts-receivable`
- i18n key: `navigation.resources.accounts_receivable`

**AccountsPayableResource** (`app/Filament/Resources/Finance/AccountsPayable/`)
- Model: `Payment::class`
- Query scope: `->where('direction', PaymentDirection::OUTBOUND)`
- Form: `direction` field hidden, pre-set to OUTBOUND
- Company select: filtered to suppliers/forwarders only
- Column labels: "Supplier/Forwarder" instead of "Company"
- Navigation: label `Accounts Payable`, icon `heroicon-o-arrow-up-right`, sort 62
- Slug: `accounts-payable`
- i18n key: `navigation.resources.accounts_payable`

### Shared Logic (Concerns)

Extract from current `PaymentForm` and `PaymentsTable` into reusable traits:

- `app/Filament/Resources/Finance/Concerns/HasPaymentFormSections.php` — allocation repeater, credit applications, outstanding items, attachment upload
- `app/Filament/Resources/Finance/Concerns/HasPaymentColumns.php` — shared table columns (date, amount, currency, allocated, unallocated, method, reference, status, created_by)
- `app/Filament/Resources/Finance/Concerns/HasPaymentApprovalActions.php` — approve/reject/cancel actions

Each resource's form/table composes these concerns and adds direction-specific customizations.

### Removed

- `app/Filament/Resources/Payments/PaymentResource.php` — deleted
- `app/Filament/Resources/Payments/` — entire directory removed
- Route `/payments` — needs redirect or 404 (no public links depend on it)

### Financial Overview Changes

The `FinancialOverview` page simplifies:
- Remove the Receivables and Payables table tabs (now separate resources with better filtering)
- Keep the Payment Schedule tab as a unified view
- Keep all existing widgets: `FinancialStatsOverview`, `CashFlowProjection`
- Add navigation links to AR/AP resources from the dashboard

### Navigation Structure (Final)

```
Finance/
  Financial Overview          sort: 1,  icon: heroicon-o-chart-bar-square
  Accounts Receivable         sort: 61, icon: heroicon-o-arrow-down-left
  Accounts Payable            sort: 62, icon: heroicon-o-arrow-up-right
  Debit Notes                 sort: 63, icon: heroicon-o-document-text
  Company Expenses            sort: 64, icon: heroicon-o-receipt-percent
```

### Domain Impact

- **No changes** to `Payment` model, `PaymentAllocation`, `PaymentScheduleItem`
- **No database migrations** for this section
- **No changes** to `GeneratePaymentScheduleAction` or any domain action
- Portal resources remain unchanged (already direction-scoped)

---

## 2. Debit Notes

### Concept

A Debit Note is a formal billing document that groups client-billable additional costs into a reviewable, issuable document. It provides audit trail, PDF generation, and client visibility for repassed costs.

### Models

**DebitNote** (`app/Domain/Financial/Models/DebitNote.php`)

| Field | Type | Description |
|-------|------|-------------|
| id | bigIncrements | PK |
| reference | string(30) | Auto-generated DN-YYYY-NNNN |
| company_id | FK → companies | Client being billed |
| proforma_invoice_id | FK → proforma_invoices, nullable | When specific to one PI |
| shipment_id | FK → shipments, nullable | When specific to one Shipment |
| total_amount | bigInteger | Total in currency (scale 10000) |
| currency_code | string(10) | Billing currency |
| status | string(30) | DebitNoteStatus enum |
| issued_at | timestamp, nullable | When issued |
| due_date | date, nullable | Payment due date |
| notes | text, nullable | Internal notes |
| created_by | FK → users, nullable | Creator |
| timestamps | | |
| softDeletes | | |

**DebitNoteLineItem** (`app/Domain/Financial/Models/DebitNoteLineItem.php`)

| Field | Type | Description |
|-------|------|-------------|
| id | bigIncrements | PK |
| debit_note_id | FK → debit_notes, cascade | Parent |
| additional_cost_id | FK → additional_costs, nullable | Source cost (if auto-populated) |
| proforma_invoice_id | FK → proforma_invoices, nullable | Which PI this line bills to |
| shipment_id | FK → shipments, nullable | Which Shipment this cost is from |
| description | string(255) | Line description |
| amount | bigInteger | Amount (scale 10000) |
| currency_code | string(10) | Currency |

### Enum

**DebitNoteStatus** (`app/Domain/Financial/Enums/DebitNoteStatus.php`)

| Value | Color | Description |
|-------|-------|-------------|
| DRAFT | gray | Created, under review |
| ISSUED | info | Sent to client |
| PARTIALLY_PAID | warning | Some lines paid |
| PAID | success | Fully paid |
| CANCELLED | danger | Voided |

### Workflow

1. **Generate:** Finance user creates a Debit Note from the DebitNotes resource or from a PI/Shipment view action
2. **Auto-populate:** System collects all `AdditionalCost` records with `billable_to = CLIENT` that haven't been included in a previous debit note, for the selected client
3. **Multi-PI freight allocation:** For costs attached to a Shipment covering multiple PIs, the system proposes proportional allocation by **gross weight or CBM** of each PI's items in that Shipment
   - The user selects the allocation criterion: weight or CBM
   - The system calculates proportions from `ShipmentItem` data (weight/dimensions per PI)
   - The user can override the calculated proportions manually
4. **Review:** Debit Note in DRAFT status. User can add/remove/edit lines, adjust amounts
5. **Issue:** Transitions to ISSUED. Updates linked `AdditionalCost.status` to INVOICED. Generates PDF.
6. **Payment Schedule integration:** Each line item with a `proforma_invoice_id` creates/updates a `PaymentScheduleItem` on that PI with `source_type = DebitNoteLineItem::class`, `source_id = line_item.id`. The schedule item label includes the debit note reference (e.g., "DN-2026-0001: Freight SH-001"). This reuses the existing `source_type`/`source_id` polymorphic fields on `PaymentScheduleItem`.
7. **Auto-reconciliation:** `PaymentAllocationObserver` monitors allocations. When all schedule items from a Debit Note are paid, the Debit Note transitions to PAID.

### Allocation Calculation

For a Shipment with items from multiple PIs, the system:

1. Groups `ShipmentItem` records by their PI (via `proformaInvoiceItem.proformaInvoice`)
2. For each PI group, sums gross weight (or calculates CBM from dimensions)
3. Calculates each PI's percentage of the total
4. Applies that percentage to the cost amount
5. Creates one `DebitNoteLineItem` per PI with the allocated amount

Example:
- Shipment SH-001: freight $1,000
- PI-001 items: 600kg (60%)
- PI-002 items: 400kg (40%)
- Result: Line 1 "Freight SH-001 — PI-001" = $600, Line 2 "Freight SH-001 — PI-002" = $400

### Filament Resource

**DebitNoteResource** (`app/Filament/Resources/Finance/DebitNotes/`)
- Pages: List, Create, View, Edit
- Navigation: label `Debit Notes`, icon `heroicon-o-document-text`, sort 63
- i18n key: `navigation.resources.debit_notes`
- Permission: `view-debit-notes`, `create-debit-notes`, `issue-debit-notes`

**Form sections:**
- Header: client select, currency, PI reference (optional), Shipment reference (optional), due date, notes
- Line items: repeater with description, amount, PI reference, Shipment reference
- Header action: "Auto-populate from unbilled costs" — fills line items from AdditionalCost records
- Header action: "Allocate Shipment costs" — triggers the weight/CBM allocation wizard

**Table columns:**
- Reference, Client, Total Amount, Currency, # Lines, Status, Issued Date, Created By

**Actions:**
- Issue (transitions DRAFT → ISSUED, generates PDF)
- Cancel (transitions to CANCELLED)
- Download PDF
- View allocations (shows which schedule items were created)

### Relation Managers

Add `DebitNotesRelationManager` to:
- `ProformaInvoiceResource` — shows debit notes linked to that PI
- `ShipmentResource` — shows debit notes with lines from that Shipment

### Domain Actions

- `IssueDebitNoteAction` — validates, transitions status, updates AdditionalCost statuses, creates schedule items
- `GenerateDebitNoteFromCostsAction` — collects unbilled costs for a client, creates DRAFT debit note
- `AllocateShipmentCostAction` — calculates weight/CBM proportions for multi-PI Shipment costs

### Observer

- `PaymentAllocationObserver` — on create/delete: checks if all schedule items with `source_type = DebitNote` for a given debit note are paid; if so, updates debit note status to PAID

---

## 3. Company Expenses — Approval Workflow

### Model Changes

Add to `CompanyExpense`:

| Field | Type | Description |
|-------|------|-------------|
| status | string(30), default 'pending_approval' | ExpenseApprovalStatus enum |
| approved_by | FK → users, nullable | Approver |
| approved_at | timestamp, nullable | Approval timestamp |
| rejected_reason | text, nullable | Reason if rejected |

### Enum

**ExpenseApprovalStatus** (`app/Domain/Finance/Enums/ExpenseApprovalStatus.php`)

| Value | Color | Description |
|-------|-------|-------------|
| DRAFT | gray | Being prepared |
| PENDING_APPROVAL | warning | Awaiting review |
| APPROVED | success | Approved |
| REJECTED | danger | Rejected with reason |

### Workflow

1. Expense created → status PENDING_APPROVAL (or DRAFT if user wants to save first)
2. Authorized user approves or rejects (with reason)
3. Approved expenses are included in financial reporting

### Filament Changes

- Add status badge column to `CompanyExpensesTable`
- Add Approve/Reject actions (same pattern as Payment approval)
- Add status filter (default: show non-rejected)
- Permission: `approve-company-expenses`

### Domain Action

- `ApproveCompanyExpenseAction` — validates permissions, transitions status, records approver and timestamp

---

## 4. Migrations

### Migration 1: `create_debit_notes_table`
```
debit_notes:
  id, reference, company_id, proforma_invoice_id (nullable),
  shipment_id (nullable), total_amount, currency_code, status,
  issued_at, due_date, notes, created_by, timestamps, softDeletes

debit_note_line_items:
  id, debit_note_id, additional_cost_id (nullable),
  proforma_invoice_id (nullable), shipment_id (nullable),
  description, amount, currency_code, timestamps
```

### Migration 2: `add_approval_fields_to_company_expenses_table`
```
Add: status (default 'pending_approval'), approved_by, approved_at, rejected_reason
Set existing records: status = 'approved' (grandfather in)
```

---

## 5. Files to Create

```
app/Domain/Financial/Models/DebitNote.php
app/Domain/Financial/Models/DebitNoteLineItem.php
app/Domain/Financial/Enums/DebitNoteStatus.php
app/Domain/Financial/Actions/IssueDebitNoteAction.php
app/Domain/Financial/Actions/GenerateDebitNoteFromCostsAction.php
app/Domain/Financial/Actions/AllocateShipmentCostAction.php
app/Domain/Financial/Observers/PaymentAllocationObserver.php
app/Domain/Finance/Enums/ExpenseApprovalStatus.php
app/Domain/Finance/Actions/ApproveCompanyExpenseAction.php

app/Filament/Resources/Finance/Concerns/HasPaymentFormSections.php
app/Filament/Resources/Finance/Concerns/HasPaymentColumns.php
app/Filament/Resources/Finance/Concerns/HasPaymentApprovalActions.php
app/Filament/Resources/Finance/AccountsReceivable/AccountsReceivableResource.php
app/Filament/Resources/Finance/AccountsReceivable/Pages/{List,Create,Edit,View}.php
app/Filament/Resources/Finance/AccountsReceivable/Tables/ReceivablesTable.php
app/Filament/Resources/Finance/AccountsReceivable/Schemas/{ReceivableForm,ReceivableInfolist}.php
app/Filament/Resources/Finance/AccountsPayable/AccountsPayableResource.php
app/Filament/Resources/Finance/AccountsPayable/Pages/{List,Create,Edit,View}.php
app/Filament/Resources/Finance/AccountsPayable/Tables/PayablesTable.php
app/Filament/Resources/Finance/AccountsPayable/Schemas/{PayableForm,PayableInfolist}.php
app/Filament/Resources/Finance/DebitNotes/DebitNoteResource.php
app/Filament/Resources/Finance/DebitNotes/Pages/{List,Create,Edit,View}.php
app/Filament/Resources/Finance/DebitNotes/Tables/DebitNotesTable.php
app/Filament/Resources/Finance/DebitNotes/Schemas/{DebitNoteForm,DebitNoteInfolist}.php
app/Filament/RelationManagers/DebitNotesRelationManager.php

database/migrations/2026_04_10_100000_create_debit_notes_tables.php
database/migrations/2026_04_10_100001_add_approval_fields_to_company_expenses_table.php
```

## 6. Files to Modify

```
app/Filament/Resources/Payments/ — DELETE entire directory
app/Filament/Pages/FinancialOverview.php — remove Receivables/Payables tabs, keep Schedule + widgets
app/Filament/Resources/Finance/CompanyExpenses/ — add approval actions, status column/filter
app/Filament/Resources/ProformaInvoices/ProformaInvoiceResource.php — add DebitNotesRelationManager
app/Filament/Resources/Shipments/ShipmentResource.php — add DebitNotesRelationManager (if exists)
app/Providers/AdminPanelProvider.php — register new resources, update navigation
lang/ — add i18n keys for new resources, labels, statuses
```

## 7. Files to Reuse (Existing Logic)

```
app/Filament/Resources/Payments/Schemas/PaymentForm.php — extract allocation logic into concerns
app/Filament/Resources/Payments/Tables/PaymentsTable.php — extract column definitions into concerns
app/Domain/Financial/Actions/ApprovePaymentAction.php — pattern reference for expense approval
app/Domain/Financial/Actions/GeneratePaymentScheduleAction.php — syncAdditionalCosts as reference
app/Domain/Logistics/Models/ShipmentItem.php — weight/dimensions for allocation calculation
```

---

## 8. Implementation Order

1. **AR/AP Split** — highest user-facing impact, zero migration risk
2. **Company Expense Approval** — simple, independent, one migration
3. **Debit Notes** — most complex, depends on understanding AR flow first

---

## 9. Verification

- [ ] Accounts Receivable list shows only INBOUND payments
- [ ] Accounts Payable list shows only OUTBOUND payments
- [ ] Creating a payment from AR pre-sets direction to INBOUND with client-only company list
- [ ] Creating a payment from AP pre-sets direction to OUTBOUND with supplier-only company list
- [ ] Allocation and credit application work identically in both AR and AP
- [ ] Approve/reject actions work in both AR and AP
- [ ] Old `/payments` route returns 404 or redirects
- [ ] Financial Overview shows Schedule tab and widgets without Receivables/Payables tables
- [ ] Company Expense creation defaults to PENDING_APPROVAL status
- [ ] Approve/Reject actions on Company Expenses work with permissions
- [ ] Debit Note auto-populates unbilled client costs
- [ ] Multi-PI Shipment freight allocates by weight/CBM correctly
- [ ] Issuing a Debit Note creates PaymentScheduleItems on the respective PIs
- [ ] Paying all schedule items from a Debit Note auto-transitions it to PAID
- [ ] AdditionalCost.status updates to INVOICED when Debit Note is issued
- [ ] Navigation shows correct order: Overview > AR > AP > Debit Notes > Expenses
