# Phase 3: Intelligence — Analysis & Implementation Plan

## Features Requested
1. Landed Cost Calculator
2. Analytical Widgets
3. Cash Flow Projection
4. Supplier Scoring

---

## Data Landscape (what exists today)

### For Landed Cost
- `ProformaInvoiceItem`: unit_price, unit_cost, quantity, incoterm
- `PurchaseOrderItem`: unit_cost, quantity, incoterm
- `AdditionalCost`: polymorphic (PI/Shipment), cost_type enum (freight, customs, insurance, testing, inspection, etc.), billable_to (client/supplier/company), currency conversion built-in
- `ShipmentItem`: links PI items ↔ PO items, has quantity/weight/volume
- `Shipment`: transport_mode, container_type, carrier, freight_forwarder, weight/volume/packages
- `ProductCosting`: base_price, bom_material_cost, labor, overhead, manufacturing_cost, markup, selling_price
- `ExchangeRate`: currency conversion with ExchangeRate::convert()

### For Cash Flow Projection
- `PaymentScheduleItem`: due_date, amount, status, currency_code, is_credit, is_blocking
- `Payment`: amount, status, direction (inbound/outbound), payment_date, currency_code
- `PaymentAllocation`: links payments to schedule items

### For Supplier Scoring
- `SupplierAudit`: total_score, result (pass/fail/conditional), audit_type, responses
- `AuditResponse`: score per criterion, passed (bool)
- `AuditCriterion`: is_critical, type (pass_fail/score), weight
- `PurchaseOrder`: status, dates (can derive delivery performance)
- `Company`: roles, products, contacts

### For Analytical Widgets
- All of the above + aggregation queries
