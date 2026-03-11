# Codebase Concerns

**Analysis Date:** 2026-03-11

---

## Tech Debt

**Duplicate Domain Namespaces — Finance vs Financial:**
- Issue: Two separate domain directories handle financial concerns with no clear separation of responsibilities. `app/Domain/Finance/` contains only `CompanyExpense.php`. `app/Domain/Financial/` contains `Payment.php`, `PaymentAllocation.php`, `PaymentScheduleItem.php`, `AdditionalCost.php`, and related Actions. The naming implies they should be merged, but the split exists.
- Files: `app/Domain/Finance/`, `app/Domain/Financial/`
- Impact: Developers adding financial features have no clear namespace to target. Cross-domain imports clutter models that span both (e.g., `FinancialStatsOverview` imports from both namespaces).
- Fix approach: Merge `app/Domain/Finance/` into `app/Domain/Financial/`. Move `CompanyExpense` and update all references.

**Duplicate Domain Namespaces — Purchasing vs PurchaseOrders:**
- Issue: `app/Domain/Purchasing/` exists with only empty `Actions/` and `Services/` subdirectories and no models. `app/Domain/PurchaseOrders/` contains the actual `PurchaseOrder.php` and `PurchaseOrderItem.php` models plus real actions.
- Files: `app/Domain/Purchasing/` (empty shell), `app/Domain/PurchaseOrders/`
- Impact: Dead directory adds confusion about where purchase order code lives.
- Fix approach: Delete `app/Domain/Purchasing/` entirely.

**Orphaned Artisan Command (Fix Script Left in Production Codebase):**
- Issue: `app/Console/Commands/DropPiSqPivot.php` is a one-off schema repair command (`php artisan fix:drop-pi-sq-pivot`) that was created to work around a migration ordering issue. It drops a pivot table in production — dangerous to leave registered.
- Files: `app/Console/Commands/DropPiSqPivot.php`
- Impact: Anyone running `php artisan fix:drop-pi-sq-pivot` on production will drop `proforma_invoice_supplier_quotation` without warning.
- Fix approach: Delete the command. The underlying migration issue it addressed has been resolved in `2026_03_06_130000_create_proforma_invoice_supplier_quotation_table.php`.

**Untracked Patch Files Committed to Working Directory:**
- Issue: Three `.patch` files (`fix_permissions.patch`, `fix_production_schedule_creation.patch`, `impex_planning_module.patch`) sit in the project root. These are untracked by git and represent changes that were applied but not cleaned up.
- Files: `fix_permissions.patch`, `fix_production_schedule_creation.patch`, `impex_planning_module.patch`
- Impact: Misleading documentation — it is unclear whether patches are applied or pending. Also exposes internal scaffolding details.
- Fix approach: Delete all three files once confirmed applied (git log confirms they are merged).

**Business Logic Tightly Coupled to Filament UI Pages:**
- Issue: Core business logic for creating Quotations, ProformaInvoices, and SupplierQuotations lives inside Filament Page action closures (`ViewInquiry.php`, `EditInquiry.php`) rather than domain Action classes. Each page has 549/491-line files embedding DB::transaction blocks inline.
- Files: `app/Filament/Resources/Inquiries/Pages/ViewInquiry.php` (491 lines), `app/Filament/Resources/Inquiries/Pages/EditInquiry.php` (549 lines), `app/Filament/Resources/Inquiries/Pages/CompareSupplierQuotations.php` (310 lines)
- Impact: Logic is duplicated between `ViewInquiry` and `EditInquiry`. Adding new workflows (API, CLI) requires duplicating the same logic again.
- Fix approach: Extract `CreateQuotationFromInquiryAction`, `CreateProformaInvoiceFromInquiryAction`, etc. into `app/Domain/` Actions. Pages become thin callers.

**`auth()->id()` Called Directly in Domain Model Observers and Actions:**
- Issue: Model boot methods and domain Actions throughout `app/Domain/` call `auth()->id()` directly, which returns `null` in CLI, queue, or seeder contexts. This silently writes `null` to `created_by`, `waived_by`, `approved_by`, and `user_id` audit fields.
- Files: `app/Domain/Financial/Models/Payment.php:64`, `app/Domain/Financial/Models/AdditionalCost.php:54`, `app/Domain/Infrastructure/Actions/TransitionStatusAction.php:59`, `app/Domain/ProformaInvoices/Actions/CancelProformaInvoiceAction.php:115`, `app/Domain/Planning/Actions/ExecuteShipmentPlanAction.php:48`, and 10+ other files.
- Impact: Running artisan commands like `ReconcileBalancesCommand` or seeders that create records will produce `created_by = null` silently. Audit logs for state transitions will record `user_id = null`.
- Fix approach: Accept an optional `?int $actingUserId = null` parameter in Actions and pass it explicitly. For model observers, use `auth()->id() ?? 0` with a sentinel system user.

**Money Calculations Using Unprotected Dynamic Attributes (N+1 Risk):**
- Issue: `ProformaInvoice::getSubtotalAttribute()`, `getTotalAttribute()`, and `getCostTotalAttribute()` call `$this->items->sum(...)` using the magic `items` property (lazy-loaded collection). When accessed on a list of PIs (e.g., in the Kanban page or financial widgets), this triggers one query per PI.
- Files: `app/Domain/ProformaInvoices/Models/ProformaInvoice.php:217–234`, `app/Filament/Pages/OrderPipelineKanban.php:150–186`
- Impact: The Kanban page fetches PI columns using `->limit(50)`, calling `$pi->items->count()` per record. At 50 PIs this is 50+1 queries for that column alone.
- Fix approach: Always eager-load `items` when accessing financial aggregates (already done in some places). Consider storing denormalized `subtotal` on the PI record and updating it via observer.

---

## Known Bugs

**`ExecuteShipmentPlanAction` Has Broken Indentation (Logic Error Risk):**
- Symptoms: Lines 16–18 of `ExecuteShipmentPlanAction.php` show a guard clause (`if ($plan->status !== ShipmentPlanStatus::CONFIRMED)`) that is not indented inside the method body — it sits at column 0, which means the PHP parser will still execute it but it signals either a merge conflict artifact or a copy-paste error that may indicate missing surrounding code.
- Files: `app/Domain/Planning/Actions/ExecuteShipmentPlanAction.php:16`
- Trigger: Calling `ExecuteShipmentPlanAction::execute()`.
- Workaround: PHP still executes the guard correctly despite indentation. No runtime failure, but the code should be reviewed for any surrounding logic that may have been accidentally deleted.

**`simplify_shipment_plan_statuses` Migration Has No Down() Rollback:**
- Symptoms: If a migration rollback is ever run past `2026_03_11_120000_simplify_shipment_plan_statuses.php`, the status values `pending_payment` and `ready_to_ship` cannot be restored. Records converted to `confirmed` lose their previous granular state permanently.
- Files: `database/migrations/2026_03_11_120000_simplify_shipment_plan_statuses.php`
- Trigger: Running `php artisan migrate:rollback`.
- Workaround: The `down()` method documents this as intentional. But combined with `make_purchase_order_item_id_required` which also has no rollback, two sequential migrations cannot be reversed safely.

**Payment Schedule Amounts Can Drift from PI Total on Regeneration:**
- Symptoms: `GeneratePaymentScheduleAction::regenerate()` recalculates amounts using integer rounding per stage (`(int) round($totalAmount * ($stage->percentage / 100))`). When percentage stages do not sum to exactly 100%, the rounding produces a total that differs from the actual PI total by ±1 minor unit (0.0001 in display currency). PAID and WAIVED items are preserved, so their stale amounts are never corrected on regeneration.
- Files: `app/Domain/Financial/Actions/GeneratePaymentScheduleAction.php:109`
- Impact: Small discrepancies appear in payment progress and finalization checks.
- Fix approach: Distribute rounding remainder to the last non-paid stage (the "largest remainder" approach).

---

## Security Considerations

**`orderByRaw` with Permission Names Interpolated from PHP Arrays:**
- Risk: `RoleForm::buildPermissionSections()` builds an `orderByRaw('FIELD(name, ' . ... . ')')` expression where the interpolated values come from a PHP `$group['permissions']` array defined in the same class. The values are hardcoded strings, not user input, so there is no immediate injection vector. However, the pattern (`"'{$p}'"` inside a raw SQL string) would become dangerous if permissions were ever sourced from user-controlled data or database values.
- Files: `app/Filament/Resources/Settings/Roles/Schemas/RoleForm.php:27`
- Current mitigation: Permissions list is hardcoded in the same file.
- Recommendations: Replace with `DB::raw()` with bindings, or sort in PHP after fetching.

**OpenAI API Key Transmitted on Every Business Card Scan (No Rate Limiting):**
- Risk: `RegisterAtFair::scanBusinessCard()` calls the OpenAI API synchronously on each upload event. There is no rate limiting, no maximum file-size check before base64-encoding, and no validation that the uploaded file is actually an image before sending to the external API. A large file or malicious upload could exhaust the OpenAI token budget or cause a slow response that blocks the Livewire request.
- Files: `app/Filament/Fair/Pages/RegisterAtFair.php:119–251`
- Current mitigation: Only INTERNAL users can access the fair panel (`canAccess()` checks user type).
- Recommendations: Add file size validation before encoding. Add a per-user or per-session rate limit on the scan action. Validate MIME type server-side before encoding.

**Business Card Images Stored on Public Disk:**
- Risk: Business card photos uploaded during fair registration are stored in `public/business-cards/` (using `Storage::disk('public')`). These are directly accessible via a public URL without any authentication.
- Files: `app/Filament/Fair/Pages/RegisterAtFair.php:952–957`, `app/Domain/CRM/Models/Company.php` (`business_card_path`, `business_card_disk` fields)
- Current mitigation: Filenames use UUID to prevent enumeration.
- Recommendations: Move to a private disk. Serve via authenticated controller route instead of public URL.

**Policies Not Registered for Several Domain Models:**
- Risk: 21 policy files exist in `app/Policies/`, but approximately 35+ domain models exist across `app/Domain/`. Models without policies include: `Contact`, `CompanyProduct`, `InquiryItem`, `SupplierQuotation` items, `ProductionSchedule`, `ShipmentPlan`, `PaymentScheduleItem`, `AdditionalCost`, `TradeFair`, `AuditCategory`, and all Planning models.
- Files: `app/Policies/` (21 files), `app/Domain/` (55+ models)
- Current mitigation: Filament canAccess/canCreate/canEdit/canDelete overrides on Resource classes provide some protection.
- Recommendations: Register policies for all sensitive models, especially `ShipmentPlan`, `ProductionSchedule`, and financial items. Verify AuthServiceProvider bindings are complete.

---

## Performance Bottlenecks

**`FinancialStatsOverview` Widget Runs 15+ Queries on Every Dashboard Load:**
- Problem: The `FinancialStatsOverview` widget (388 lines) performs 15+ `selectRaw` aggregation queries per render, covering receivables, payables, overdue items, received payments, made payments, and operational expenses grouped by currency. It has `$isLazy = false`, meaning it blocks the initial page render.
- Files: `app/Filament/Pages/Widgets/FinancialStatsOverview.php`
- Cause: No caching, no aggregation at database level (multi-currency requires currency-by-currency grouping), no lazy loading.
- Improvement path: Wrap results in `cache()->remember()` with a short TTL (30–60 seconds). Enable `$isLazy = true` so the widget defers loading.

**`CompanyFinancialStatement` Widget Loads All Historical Invoices/POs Per Render:**
- Problem: The `CompanyFinancialStatement` widget fetches all non-cancelled `ProformaInvoice` records for a company with `paymentScheduleItems` eager-loaded, and separately all non-cancelled `PurchaseOrder` records. For active companies with 100+ PIs, this is a significant page-load query.
- Files: `app/Filament/Resources/CRM/Companies/Widgets/CompanyFinancialStatement.php:55–60`
- Cause: No pagination, no limit, `$isLazy = false`.
- Improvement path: Paginate or limit to last N records. Add `$isLazy = true`. Consider a dedicated SQL summary query instead of loading full records.

**Order Pipeline Kanban Runs 6 Separate Full-Table Queries on Page Load:**
- Problem: `OrderPipelineKanban::getColumns()` runs 6 independent queries (each fetching up to 50 records with eager loads) synchronously in a single page request, totalling hundreds of rows loaded into PHP memory before rendering.
- Files: `app/Filament/Pages/OrderPipelineKanban.php:53–200`
- Cause: No caching, synchronous multi-query approach.
- Improvement path: Cache each column query for 1–2 minutes. Consider Livewire lazy rendering per column.

**Exchange Rate Conversion Queries Not Cached:**
- Problem: `ExchangeRate::convert()` runs up to 2 database queries per conversion call (fetching the latest approved rate). In the financial overview and cost calculation widgets, this is called multiple times per render across different currency pairs.
- Files: `app/Domain/Settings/Models/ExchangeRate.php:75–105`
- Cause: No in-request memoization or cache layer.
- Improvement path: Add in-memory memoization within the request (static array keyed by `fromId:toId:date`). Alternatively cache in Redis with a 1-hour TTL.

---

## Fragile Areas

**`GenerateReferenceAction` Has a Race Condition Window on First Insert:**
- Files: `app/Domain/Infrastructure/Actions/GenerateReferenceAction.php`
- Why fragile: The action creates a `ReferenceSequence` record inside a transaction with `lockForUpdate`, but if two concurrent requests both find no sequence row (e.g., first PI of the year), they may both attempt `ReferenceSequence::create()` in the same transaction isolation window, causing a duplicate entry error. The lock is acquired after the insert, not before finding the row.
- Safe modification: Rewrite to use `INSERT ... ON DUPLICATE KEY UPDATE next_number = next_number + 1` (MySQL) or use `firstOrCreate` before applying `lockForUpdate`. The current pattern has `lockForUpdate()` chained on a `find()` call after `create()`, which does not prevent the race on the initial creation.
- Test coverage: Not tested in the test suite.

**`FileUpload inside Repeater` Workaround in Fair Registration:**
- Files: `app/Filament/Fair/Pages/RegisterAtFair.php:1001–1003`
- Why fragile: The fair registration form cannot use `FileUpload` inside a `Repeater` (Filament v4 limitation, referenced issue #13636). Product photos are stored as top-level fields (`product_photo_0`...`product_photo_4`) outside the repeater. This coupling between field naming and array index is fragile — any change to the products repeater order or count can silently misalign photos with products.
- Safe modification: Only modify by updating both the field definitions and the submission handler (`handleSubmit()`) in lockstep.
- Test coverage: None.

**Payment Schedule Regeneration Does Not Handle Partially Paid Stages:**
- Files: `app/Domain/Financial/Actions/GeneratePaymentScheduleAction.php:88–144`
- Why fragile: `regenerate()` preserves items that have `allocations` but deletes and recreates others. If a stage has been partially paid (allocations exist but status is not PAID), the item is preserved with the old amount — it is never updated to reflect the new PI total. The percentage recalculation only applies to items that can be deleted.
- Safe modification: Any change to the regeneration logic must consider the partially-paid case. Add explicit tests before modifying.
- Test coverage: Covered partially in `tests/Feature/GeneratePaymentScheduleActionTest.php` but the partially-paid case is not tested.

**Shipment Plan Execution Fails Silently for PI Items Without a PO Item:**
- Files: `app/Domain/Planning/Actions/ExecuteShipmentPlanAction.php:67–71`
- Why fragile: If a `ProformaInvoiceItem` has no linked `PurchaseOrderItem` (because POs were not generated before execution, or the PO was cancelled), `ExecuteShipmentPlanAction` throws a `\RuntimeException`. This exception propagates up but the DB::transaction wrapping means any partially-created `ShipmentItem` records are rolled back. However, the `ShipmentPlan.status` remains `CONFIRMED` and the newly created `Shipment` (if `$existingShipment` was not passed) is also rolled back — leaving no trace that execution was attempted.
- Safe modification: Add a pre-flight validation step to `ExecuteShipmentPlanAction` before the transaction: verify all plan items have linked PO items and surface a list of problems before attempting execution.
- Test coverage: None.

---

## Scaling Limits

**No Queue Infrastructure for Email or External API Calls:**
- Current capacity: Emails (`Mail::to()->send()`) are dispatched synchronously on the request thread in `RegisterAtFair`. The OpenAI vision API call also runs synchronously with a 30-second timeout. Under normal fair traffic (10–20 concurrent registrations), this blocks each Livewire request for up to 30+ seconds.
- Limit: Any burst of fair registrations will queue Livewire requests behind OpenAI timeout windows.
- Scaling path: Move `Mail::send()` to `Mail::queue()`. Wrap the OpenAI scan call in a Livewire action that dispatches to a queue and polls for results.

**`Product::active()` Scope Called Without Pagination in Dropdowns:**
- Current capacity: Product selectors in `ItemsRelationManager` for PI, PO, and Quotation call `Product::active()->orderBy('name')->get()` to populate option lists. This loads the entire active product catalog into memory.
- Limit: Beyond ~5,000 active products, dropdown options will slow significantly and may hit PHP memory limits.
- Scaling path: Convert all product selects to use Filament's `searchable()` with `getSearchResultsUsing()` that runs a LIKE query with a minimum-character threshold rather than loading all products.

---

## Dependencies at Risk

**`filament/filament: ^4.0` — Pre-Release Beta:**
- Risk: Filament v4 is referenced throughout (e.g., `Filament\Schemas\Components\Section`, `Filament\Schemas\Schema` in place of v3's `Filament\Forms\Components\Section`). Filament v4 was in active development/beta during this codebase's build period. Some APIs may change before stable release.
- Impact: Upgrading to a stable v4 release may require updating import namespaces and method signatures.
- Migration plan: Monitor `filament/filament` changelog for v4 stable. Pin to a specific minor version in `composer.json` rather than `^4.0`.

**`brick/money: ^0.11.1` — Not Used Consistently:**
- Risk: `brick/money` is listed as a dependency but the codebase implements its own `Money` utility class (`app/Domain/Infrastructure/Support/Money.php`) using a custom 10,000-scale integer representation rather than using `brick/money`'s `Money` object. The `brick/money` library appears unused.
- Impact: Unused dependency bloat. The custom `Money` class lacks currency-awareness (no `Currency` object attachment to amounts).
- Migration plan: Either adopt `brick/money` fully (replacing the custom `Money` class) or remove it from `composer.json`.

---

## Missing Critical Features

**No Automated Test Coverage for Core Financial Flows:**
- Problem: The test suite has 11 PHP test files total. Only 4 files test actual domain logic: `MoneyTest.php`, `CommercialInvoicePricingTest.php`, `PaymentScheduleTest.php`, and `CancelProformaInvoiceActionTest.php`. There are zero tests for: payment allocation, exchange rate conversion, shipment execution, reference number generation, product import, and the full PI-to-PO-to-Shipment workflow.
- Files: `tests/` (11 files)
- Risk: Any refactoring of financial calculations, state machine transitions, or planning actions has no safety net.
- Priority: High — the financial calculation and state machine code is critical path for the business.

**No Scheduled Health Checks or Alerting for Exchange Rate Staleness:**
- Problem: Exchange rates are fetched manually or via `php artisan exchange-rates:fetch`. There is no cron schedule registered in `app/Console/Kernel.php` (the file is not present; scheduled tasks would need to be in a service provider or registered via `routes/console.php`). If exchange rates go stale, all multi-currency financial conversions silently return `null` (see `ExchangeRate::convert()` line 100: `if (!$rateBaseToFrom || !$rateBaseToTo) { return null; }`).
- Files: `app/Domain/Settings/Console/FetchExchangeRatesCommand.php`, `app/Domain/Settings/Models/ExchangeRate.php:100`
- Blocks: Multi-currency financial dashboard will show blank/zero values for any currency pair without a recent approved rate.
- Priority: Medium.

---

## Test Coverage Gaps

**State Machine Transitions — Not Tested:**
- What's not tested: `HasStateMachine::transitionTo()` and `TransitionStatusAction::execute()` for all models (PI, PO, Quotation, Shipment, ShipmentPlan, SupplierQuotation).
- Files: `app/Domain/Infrastructure/Actions/TransitionStatusAction.php`, `app/Domain/Infrastructure/Traits/HasStateMachine.php`
- Risk: Invalid transitions could succeed silently if `allowedTransitions()` is misconfigured on any model.
- Priority: High.

**Product Import Service — Not Tested:**
- What's not tested: `ProductImportService::parseFile()` and `importRows()` in `app/Domain/Catalog/Actions/Import/ProductImportService.php` (561 lines).
- Files: `app/Domain/Catalog/Actions/Import/ProductImportService.php`
- Risk: XLSX parsing errors, category mismatches, and duplicate detection logic are untested. A regression here silently corrupts the product catalog.
- Priority: High.

**Shipment Plan Lifecycle — Not Tested:**
- What's not tested: `ConfirmShipmentPlanAction`, `ExecuteShipmentPlanAction`, `ReconcileShipmentPlanAction`, `UpdateShipmentPlanAction`.
- Files: `app/Domain/Planning/Actions/`
- Risk: The planning module is the newest and most complex area. The `ReconcileShipmentPlanAction` modifies payment schedule items based on actual vs planned quantity differences — errors here cause incorrect payment requests.
- Priority: High.

**Exchange Rate Conversion — Not Tested:**
- What's not tested: `ExchangeRate::convert()` cross-currency triangulation logic.
- Files: `app/Domain/Settings/Models/ExchangeRate.php:75–105`
- Risk: Incorrect triangulation (converting between two non-base currencies) would silently compute wrong financial totals on the dashboard.
- Priority: High.

**Portal Data Isolation — Not Tested:**
- What's not tested: That Client Portal users can only see their own company's PIs, shipments, payments, and quotations. That Supplier Portal users can only see their own company's POs and payments.
- Files: `app/Filament/Portal/`, `app/Filament/SupplierPortal/`, `app/Models/User.php:88–91`
- Risk: A regression in `canAccessTenant()` or `tenantOwnershipRelationshipName` would expose other companies' data.
- Priority: Critical.

---

*Concerns audit: 2026-03-11*
