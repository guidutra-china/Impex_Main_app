# Architecture

**Analysis Date:** 2026-03-11

## Pattern Overview

**Overall:** Domain-Driven Design (DDD) with Filament Admin Panel as the presentation layer.

**Key Characteristics:**
- Business logic is organized into self-contained Domain modules under `app/Domain/`
- Each Domain owns its Models, Actions, Enums, DTOs, Services, and Traits
- Filament resources (`app/Filament/`) act as the UI/presentation layer and delegate all mutations to Domain Actions
- A shared `Infrastructure` domain provides cross-cutting concerns: state machines, document generation, reference numbering, and money handling
- Four distinct Filament panels serve different user types (admin, portal, supplier-portal, fair)

## Layers

**Domain Layer:**
- Purpose: Core business logic, entities, and rules
- Location: `app/Domain/`
- Contains: Eloquent Models, Action classes, Enums, DataTransferObjects, Services, Traits
- Depends on: Laravel framework (Eloquent), Infrastructure domain
- Used by: Filament presentation layer, HTTP controllers, Console commands

**Presentation Layer (Filament):**
- Purpose: Admin UI and client-facing portals
- Location: `app/Filament/`
- Contains: Resources, Pages, Widgets, RelationManagers, Actions (UI actions)
- Depends on: Domain models and Action classes
- Used by: End users via browser

**Infrastructure Domain:**
- Purpose: Reusable cross-cutting concerns shared by all business domains
- Location: `app/Domain/Infrastructure/`
- Contains:
  - `Traits/HasStateMachine.php` — enforces allowed status transitions on models
  - `Traits/HasReference.php` — auto-generates sequential document references (INQ-00001, PI-00001, etc.)
  - `Traits/HasDocuments.php` — polymorphic document/file attachment
  - `Actions/TransitionStatusAction.php` — DB-transactional status change with audit log
  - `Actions/GenerateReferenceAction.php` — reference sequence generation
  - `Services/DocumentService.php` — versioned file storage (uploaded and generated)
  - `Pdf/` — PDF generation subsystem with templates per document type
  - `Support/Money.php` — integer-based monetary arithmetic (scale: 10,000 units per 1 currency unit)
  - `Models/StateTransition.php` — polymorphic audit log for all status changes
  - `Models/Document.php` / `DocumentVersion.php` — polymorphic versioned file storage

**HTTP Layer:**
- Purpose: Non-Filament web routes (signed file downloads, portal document access)
- Location: `app/Http/Controllers/`
- Contains: `DocumentVersionDownloadController`, `FileDownloadController`, `PortalDocumentDownloadController`
- Depends on: Domain models

**Policy Layer:**
- Purpose: Authorization gates
- Location: `app/Policies/`
- Contains: One policy per domain entity (e.g., `ProformaInvoicePolicy.php`)
- Uses: Spatie Permission package roles/permissions via `$user->can('action-resource')`

## Data Flow

**Standard CRUD Operation (via Filament):**

1. User interacts with a Filament Resource page (`app/Filament/Resources/...`)
2. Form schema (defined in `Schemas/` subdirectory) collects input
3. Filament page calls the appropriate Domain Action (e.g., `CreateProformaInvoice` page → `ProformaInvoice::create()` or a dedicated Action)
4. Domain Action executes within a DB transaction, updates the model, and runs side effects
5. Model Eloquent events (e.g., `booted()`) may auto-set defaults on creation

**Status Transition Flow:**

1. Filament `StatusTransitionActions::make()` renders per-record action buttons based on `$record->getAllowedNextStatuses()`
2. User clicks a transition button; notes/confirmation modal shown for destructive transitions
3. `TransitionStatusAction::execute()` validates against `Model::allowedTransitions()`, updates status column, logs to `state_transitions` table, runs optional `$sideEffects` closure
4. Side effects cascade to related records (e.g., cancelling a PI auto-cancels its POs and ShipmentPlans)

**Document/PDF Flow:**

1. User triggers `GeneratePdfAction` (Filament UI action)
2. Action resolves the correct `PdfTemplate` class for the document type
3. Template renders a Blade view via `PdfRenderer`
4. `DocumentService::storeGenerated()` saves the PDF to private disk; if a prior version exists, the old file is archived as a `DocumentVersion`
5. `Document` record is linked to the parent model via polymorphic relation

**State Management:**
- Server-side only; Filament manages UI state via Livewire
- No client-side state management library

## Key Abstractions

**Domain Action:**
- Purpose: Encapsulates a single business operation (one class, one public `execute()` method)
- Examples: `app/Domain/ProformaInvoices/Actions/CancelProformaInvoiceAction.php`, `app/Domain/Financial/Actions/GeneratePaymentScheduleAction.php`, `app/Domain/Planning/Actions/ExecuteShipmentPlanAction.php`
- Pattern: `class XxxAction { public function execute(...): Model }` — invoked via `app(XxxAction::class)->execute()`

**HasStateMachine Trait:**
- Purpose: Enforce allowed status transitions and provide transition helpers on any Model
- Location: `app/Domain/Infrastructure/Traits/HasStateMachine.php`
- Pattern: Model implements `allowedTransitions(): array` mapping `from_value => [to_value, ...]`. Call `$model->transitionTo($status)` or use `TransitionStatusAction` directly.

**Money Value Object:**
- Purpose: Integer-based monetary storage to avoid floating-point errors
- Location: `app/Domain/Infrastructure/Support/Money.php`
- Pattern: All monetary columns stored as integers (scale: 10,000). Use `Money::toMinor(float)` to convert to storage, `Money::toMajor(int)` to convert for display.

**PDF Template System:**
- Purpose: Typed PDF generation per document kind
- Location: `app/Domain/Infrastructure/Pdf/Templates/`
- Pattern: Each template extends `AbstractPdfTemplate`, implements `GeneratesPdf` contract. Templates: `ProformaInvoicePdfTemplate`, `PurchaseOrderPdfTemplate`, `QuotationPdfTemplate`, `CommercialInvoicePdfTemplate`, `PackingListPdfTemplate`, `RfqPdfTemplate`, `CostStatementPdfTemplate`, `CustomPricePdfTemplate`.

**Filament Panel Separation:**
- Purpose: Different UI contexts per user type
- Examples: `AdminPanelProvider` (path: `/panel`), `PortalPanelProvider` (path: `/portal`, tenant-scoped by Company), `SupplierPortalPanelProvider` (path: `/supplier-portal`), `FairPanelProvider`
- Pattern: Access control enforced in `User::canAccessPanel()` via `UserType` enum

**Filament Resource Schema Extraction:**
- Purpose: Keep Resource classes lean; form/infolist/table logic lives in dedicated classes
- Examples: `app/Filament/Resources/ProformaInvoices/Schemas/ProformaInvoiceForm.php`, `ProformaInvoiceInfolist.php`, `Tables/ProformaInvoicesTable.php`
- Pattern: `XxxResource::form()` delegates to `XxxForm::configure($schema)`, table delegates to `XxxTable::configure($table)`

## Entry Points

**Filament Admin Panel:**
- Location: `app/Providers/Filament/AdminPanelProvider.php`
- Triggers: Any request to `/panel/*`
- Responsibilities: Internal user CRUD, all trade operations (Inquiries → Quotations → PI → POs → Shipments → Payments)

**Client Portal:**
- Location: `app/Providers/Filament/PortalPanelProvider.php`
- Triggers: Any request to `/portal/*`
- Responsibilities: Client-facing read/approve views for their own company's PIs, Quotations, Shipments, Payments (tenant-scoped by `company_id`)

**Supplier Portal:**
- Location: `app/Providers/Filament/SupplierPortalPanelProvider.php`
- Triggers: Any request to `/supplier-portal/*`
- Responsibilities: Supplier-facing views for their POs, Shipments, Payments, Products

**Fair Panel:**
- Location: `app/Providers/Filament/FairPanelProvider.php`
- Triggers: Requests to the fair panel path
- Responsibilities: Trade fair management (registrations, dashboards) for internal staff

**Artisan Entry Points:**
- Location: `app/Console/Commands/`, `app/Domain/Infrastructure/Console/`, `app/Domain/Settings/Console/`, `app/Domain/Catalog/Console/`
- Key commands: `ReconcileBalancesCommand` (infrastructure), `DropPiSqPivot` (data fix)

## Error Handling

**Strategy:** Exception-based with Filament Notification feedback to users

**Patterns:**
- `TransitionStatusAction` throws `\InvalidArgumentException` for disallowed transitions; Filament `StatusTransitionActions` catches `\Throwable` and sends a danger Notification to the user
- DB operations wrapped in `DB::transaction()` in Actions; exceptions propagate naturally to roll back
- Filament displays form validation errors inline; server exceptions surface as error notifications

## Cross-Cutting Concerns

**Activity Logging:** Spatie `laravel-activitylog` — models opt in via `LogsActivity` trait (e.g., `ProformaInvoice`, `ShipmentPlan`). Log name matches domain document type.

**Authorization:** Spatie `laravel-permission` — string-based permissions (e.g., `'view-proforma-invoices'`, `'portal:view-proforma-invoices'`). Policies in `app/Policies/` check `$user->can(...)`. Filament Resources gate via `canAccess()` override.

**Localization:** `SetLocale` middleware sets app locale from user preference. All labels use `__('key')` translation strings. Translation files in `lang/en/`.

**Tenancy:** Filament multi-tenancy via `HasTenants` on `User`; tenant is `Company`. Portal and SupplierPortal panels scope data by `company_id`.

**Soft Deletes:** Applied on primary business models (Inquiry, ProformaInvoice, etc.).

---

*Architecture analysis: 2026-03-11*
