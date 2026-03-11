# Codebase Structure

**Analysis Date:** 2026-03-11

## Directory Layout

```
Impex_Main_app/
├── app/
│   ├── Console/
│   │   └── Commands/           # Artisan commands (app-level)
│   ├── Domain/                 # All business logic, organized by domain
│   │   ├── Auth/               # Authentication domain (DTOs, enums, models, services)
│   │   ├── Catalog/            # Product catalog (Products, Categories, Tags)
│   │   ├── CRM/                # Companies, Contacts, CRM relations
│   │   ├── Documents/          # Placeholder domain (logic lives in Infrastructure)
│   │   ├── Finance/            # Company expenses
│   │   ├── Financial/          # Payment schedules, additional costs, payments (shared)
│   │   ├── Infrastructure/     # Cross-cutting concerns (state machine, references, PDF, Money)
│   │   ├── Inquiries/          # Client inquiries and project team
│   │   ├── Logistics/          # Shipments, packing lists
│   │   ├── Planning/           # Production schedules, shipment plans
│   │   ├── ProformaInvoices/   # PI lifecycle and cancellation
│   │   ├── PurchaseOrders/     # PO generation and lifecycle
│   │   ├── Purchasing/         # Placeholder domain (scaffolded, empty)
│   │   ├── Quotations/         # Client quotations and versions
│   │   ├── Settings/           # Currencies, payment terms, bank accounts, etc.
│   │   ├── SupplierAudits/     # Supplier audit scoring and documents
│   │   ├── SupplierQuotations/ # RFQ / supplier quotations
│   │   ├── TradeFairs/         # Trade fair model
│   │   └── Users/              # UserType enum
│   ├── Filament/               # Presentation layer (Filament panels)
│   │   ├── Actions/            # Reusable Filament UI actions (PDF, status transition, import)
│   │   ├── Auth/               # Custom EditProfile page
│   │   ├── Fair/               # Fair panel pages
│   │   ├── Pages/              # Admin panel pages and widgets
│   │   ├── Portal/             # Client portal resources, pages, widgets
│   │   ├── RelationManagers/   # Shared relation managers (Documents, Payments, etc.)
│   │   ├── Resources/          # Admin panel resources (grouped by domain)
│   │   │   ├── Audit/
│   │   │   ├── Catalog/
│   │   │   │   ├── Categories/
│   │   │   │   ├── Products/
│   │   │   │   └── Tags/
│   │   │   ├── CRM/
│   │   │   │   ├── Companies/
│   │   │   │   └── SupplierAudits/
│   │   │   ├── Finance/
│   │   │   │   └── CompanyExpenses/
│   │   │   ├── Inquiries/
│   │   │   ├── Payments/
│   │   │   ├── ProductionSchedules/
│   │   │   ├── ProformaInvoices/
│   │   │   ├── PurchaseOrders/
│   │   │   ├── Quotations/
│   │   │   ├── Settings/       # CRUD for lookup tables (currencies, bank accounts, etc.)
│   │   │   ├── ShipmentPlans/
│   │   │   ├── Shipments/
│   │   │   ├── SupplierQuotations/
│   │   │   └── Users/
│   │   ├── SupplierPortal/     # Supplier portal resources, pages, widgets
│   │   └── Widgets/            # Admin panel dashboard widgets
│   ├── Http/
│   │   ├── Controllers/        # Minimal: file download controllers only
│   │   └── Middleware/         # SetLocale middleware
│   ├── Mail/                   # Mailable classes (DocumentMail, FairInquiryMail)
│   ├── Models/                 # App-level models (User only)
│   ├── Policies/               # Authorization policies (one per resource type)
│   └── Providers/
│       ├── AppServiceProvider.php
│       └── Filament/           # One PanelProvider per panel
│           ├── AdminPanelProvider.php
│           ├── FairPanelProvider.php
│           ├── PortalPanelProvider.php
│           └── SupplierPortalPanelProvider.php
├── bootstrap/                  # Laravel bootstrap
├── config/                     # Laravel and package config files
├── database/
│   ├── factories/
│   ├── migrations/             # Timestamped migrations
│   └── seeders/
├── lang/
│   └── en/                     # English translation files
├── public/                     # Web root
├── resources/
│   ├── css/filament/           # Per-panel Tailwind theme CSS
│   │   ├── admin/
│   │   ├── fair/
│   │   ├── portal/
│   │   └── supplier-portal/
│   ├── js/                     # Minimal JS (Vite entry)
│   └── views/
│       ├── emails/             # Email Blade templates
│       ├── filament/           # Filament component/widget view overrides
│       ├── pdf/                # PDF Blade templates (per document type)
│       │   └── layouts/
│       ├── portal/             # Portal panel Blade partials
│       └── supplier-portal/    # Supplier portal Blade partials
├── routes/
│   ├── console.php
│   └── web.php                 # Minimal: root redirect + 3 signed file download routes
├── storage/
│   └── app/
│       ├── private/            # Private documents (proformainvoice, purchaseorder, etc.)
│       └── public/             # Public assets (logos, product avatars, product docs)
├── tests/
│   ├── Feature/                # Feature tests (action integration tests)
│   └── Unit/                   # Unit tests (Money, PaymentSchedule)
├── composer.json
├── package.json
└── vite.config.js
```

## Directory Purposes

**`app/Domain/{DomainName}/`:**
- Purpose: Self-contained business domain
- Standard subdirectories in each domain:
  - `Actions/` — Single-responsibility action classes (`XxxAction::execute()`)
  - `DataTransferObjects/` — DTOs for passing structured data between layers
  - `Enums/` — PHP backed enums for domain constants and status values
  - `Models/` — Eloquent models with relationships, casts, scopes, and business logic
  - `Services/` — Stateful or complex multi-step service classes
  - `Traits/` — Reusable behavior mixed into models (only used in Infrastructure and Financial)
  - `Console/` — Domain-specific Artisan commands
  - `Observers/` — Eloquent observers (only in Inquiries)
- Key files in Infrastructure: `app/Domain/Infrastructure/Support/Money.php`, `app/Domain/Infrastructure/Traits/HasStateMachine.php`

**`app/Filament/Resources/{ResourceName}/`:**
- Purpose: Admin panel CRUD for one domain entity
- Standard subdirectories:
  - `Pages/` — `ListXxx.php`, `CreateXxx.php`, `EditXxx.php`, `ViewXxx.php`
  - `Schemas/` — `XxxForm.php` (form schema), `XxxInfolist.php` (view schema)
  - `Tables/` — `XxxTable.php` (table configuration)
  - `RelationManagers/` — Inline related record management tabs
  - `Widgets/` — Resource-scoped stats and charts
- Root file: `XxxResource.php` — registers pages, relations, widgets; delegates to Schema/Table classes

**`app/Filament/RelationManagers/`:**
- Purpose: Shared relation managers reused across multiple resources
- Key files: `AdditionalCostsRelationManager.php`, `DocumentsRelationManager.php`, `PaymentScheduleRelationManager.php`, `PaymentsRelationManager.php`

**`resources/views/pdf/`:**
- Purpose: Blade templates rendered by the PDF subsystem
- Used by: `app/Domain/Infrastructure/Pdf/Templates/*.php`

## Key File Locations

**Entry Points:**
- `app/Providers/Filament/AdminPanelProvider.php`: Admin panel configuration and resource discovery
- `app/Providers/Filament/PortalPanelProvider.php`: Client portal with Company tenancy
- `app/Providers/Filament/SupplierPortalPanelProvider.php`: Supplier portal with Company tenancy
- `app/Providers/Filament/FairPanelProvider.php`: Trade fair panel
- `routes/web.php`: Signed file download routes

**Core Domain Models:**
- `app/Domain/Inquiries/Models/Inquiry.php`: Top of the trade lifecycle
- `app/Domain/Quotations/Models/Quotation.php`: Client-facing quotes
- `app/Domain/SupplierQuotations/Models/SupplierQuotation.php`: Supplier RFQs
- `app/Domain/ProformaInvoices/Models/ProformaInvoice.php`: Confirmed sale document
- `app/Domain/PurchaseOrders/Models/PurchaseOrder.php`: Supplier order
- `app/Domain/Planning/Models/ShipmentPlan.php`: Pre-shipment grouping
- `app/Domain/Logistics/Models/Shipment.php`: Actual shipment
- `app/Domain/Financial/Models/Payment.php`: Inbound/outbound payment
- `app/Domain/Financial/Models/PaymentScheduleItem.php`: Milestone-based payment schedule

**Cross-Cutting Infrastructure:**
- `app/Domain/Infrastructure/Traits/HasStateMachine.php`: State machine trait (implement `allowedTransitions()`)
- `app/Domain/Infrastructure/Actions/TransitionStatusAction.php`: DB-transactional status change
- `app/Domain/Infrastructure/Traits/HasReference.php`: Auto reference generation (implement `getDocumentType()`)
- `app/Domain/Infrastructure/Support/Money.php`: Integer money arithmetic
- `app/Domain/Infrastructure/Services/DocumentService.php`: Versioned document storage
- `app/Domain/Infrastructure/Models/StateTransition.php`: Polymorphic status change audit log

**UI Actions (reusable Filament):**
- `app/Filament/Actions/StatusTransitionActions.php`: Builds transition action buttons from enum
- `app/Filament/Actions/GeneratePdfAction.php`: Trigger PDF generation from a resource page

**User / Auth:**
- `app/Models/User.php`: User model with `canAccessPanel()` panel gating and `HasTenants`
- `app/Policies/`: One policy file per major resource type

**Configuration:**
- `config/permission.php`: Spatie permission config
- `config/settings.php`: App settings config

## Naming Conventions

**Files:**
- Domain Action: `{Verb}{Entity}Action.php` (e.g., `CancelProformaInvoiceAction.php`, `GeneratePaymentScheduleAction.php`)
- Domain Model: `PascalCase` matching entity name (e.g., `ProformaInvoice.php`, `ShipmentPlan.php`)
- Enum: `{Entity}Status.php` for status enums; descriptive names for others (e.g., `Incoterm.php`, `UserType.php`)
- Filament Resource: `{Entity}Resource.php`
- Filament Schema: `{Entity}Form.php`, `{Entity}Infolist.php`
- Filament Table: `{Entity}sTable.php` (plural)
- Policy: `{Entity}Policy.php`

**Directories:**
- Domain names: `PascalCase` singular nouns (e.g., `ProformaInvoices`, `Logistics`, `Infrastructure`)
- Filament resource subdirs: `PascalCase` plural names matching entity (e.g., `ProformaInvoices/`, `PurchaseOrders/`)

**Namespaces:**
- Domain: `App\Domain\{DomainName}\{Subdirectory}` (e.g., `App\Domain\ProformaInvoices\Actions`)
- Filament admin: `App\Filament\Resources\{GroupName}\{EntityName}`
- Filament portal: `App\Filament\Portal\Resources\{EntityName}Resource`
- Filament supplier portal: `App\Filament\SupplierPortal\Resources\{EntityName}Resource`

## Where to Add New Code

**New Business Domain (e.g., new entity):**
- Create `app/Domain/{NewDomain}/` with subdirectories: `Actions/`, `DataTransferObjects/`, `Enums/`, `Models/`, `Services/`
- Add model that uses `HasReference`, `HasStateMachine`, `SoftDeletes` as appropriate
- Add `app/Policies/{Entity}Policy.php`
- Add Filament resource under `app/Filament/Resources/{Group}/{EntityName}/`

**New Action on existing domain:**
- Create `app/Domain/{DomainName}/Actions/{VerbEntity}Action.php`
- Single public `execute()` method
- Use `app(TransitionStatusAction::class)->execute()` for status changes

**New Filament Resource:**
- Primary resource file: `app/Filament/Resources/{Group}/{EntityName}/{EntityName}Resource.php`
- Form schema: `app/Filament/Resources/{Group}/{EntityName}/Schemas/{EntityName}Form.php`
- Infolist schema: `app/Filament/Resources/{Group}/{EntityName}/Schemas/{EntityName}Infolist.php`
- Table: `app/Filament/Resources/{Group}/{EntityName}/Tables/{EntityName}sTable.php`
- Pages: `app/Filament/Resources/{Group}/{EntityName}/Pages/{List|Create|Edit|View}{EntityName}.php`

**New Status on existing entity:**
- Add case to the entity's `Enums/{Entity}Status.php`
- Update `allowedTransitions()` in the Model
- Add any side-effect logic in a Domain Action

**New PDF document type:**
- Add case to `app/Domain/Infrastructure/Enums/DocumentType.php`
- Create `app/Domain/Infrastructure/Pdf/Templates/{Type}PdfTemplate.php` extending `AbstractPdfTemplate`
- Create Blade template under `resources/views/pdf/`

**New shared relation manager:**
- Add to `app/Filament/RelationManagers/`
- Register in relevant `XxxResource::getRelations()`

## Special Directories

**`app/Domain/Infrastructure/`:**
- Purpose: Shared traits, actions, models, and services used by all other domains
- Generated: No
- Committed: Yes

**`app/Domain/Purchasing/` and `app/Domain/Documents/` and `app/Domain/Finance/` (partial):**
- Purpose: Scaffolded domains with `.gitkeep` placeholders — not yet implemented
- Generated: No (manually scaffolded)
- Committed: Yes

**`storage/app/private/documents/`:**
- Purpose: Private document storage (PDFs, uploads) organized by `{model_type}/{id}/{document_type}/`
- Generated: Yes (at runtime)
- Committed: No

**`storage/app/public/`:**
- Purpose: Publicly accessible assets (logos, product avatars, product docs)
- Generated: Yes (at runtime)
- Committed: No

**`.planning/codebase/`:**
- Purpose: GSD codebase map documents
- Generated: Yes (by `/gsd:map-codebase`)
- Committed: Yes

---

*Structure analysis: 2026-03-11*
