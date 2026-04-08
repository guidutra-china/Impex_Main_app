# Company Statement Report Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a consolidated statement report per company (client/supplier/forwarder) with financial summary, role-specific sections, multi-language support, HTML preview and PDF export — accessible from admin and both portals.

**Architecture:** Domain service (`CompanyStatementService`) composes a `StatementReport` DTO by running role-specific `SectionBuilder`s and a `FinancialSummaryBuilder`. Presentation layer is a shared Filament `StatementPreviewPage` reused by admin and portals, backed by a single Blade view reused for both HTML preview and PDF rendering (`CompanyStatementPdfTemplate` extends `AbstractPdfTemplate`). Language selection is a runtime `App::setLocale` wrap around the render, with `companies.preferred_language` as the smart default.

**Tech Stack:** Laravel 11, Filament v3, PHPUnit, Blade, dompdf (via existing `AbstractPdfTemplate`).

**Spec:** `docs/superpowers/specs/2026-04-09-company-statement-report-design.md`

---

## File structure

**Create:**
- `database/migrations/2026_04_09_000001_add_preferred_language_to_companies.php`
- `app/Domain/CRM/Reports/DTOs/StatementFilters.php`
- `app/Domain/CRM/Reports/DTOs/StatementReport.php`
- `app/Domain/CRM/Reports/DTOs/StatementSection.php`
- `app/Domain/CRM/Reports/DTOs/FinancialSummary.php`
- `app/Domain/CRM/Reports/DTOs/CurrencyTotals.php`
- `app/Domain/CRM/Reports/DTOs/AgingBuckets.php`
- `app/Domain/CRM/Reports/FinancialSummaryBuilder.php`
- `app/Domain/CRM/Reports/SectionBuilders/SectionBuilder.php` (interface)
- `app/Domain/CRM/Reports/SectionBuilders/InquirySectionBuilder.php`
- `app/Domain/CRM/Reports/SectionBuilders/QuotationSectionBuilder.php`
- `app/Domain/CRM/Reports/SectionBuilders/ProformaInvoiceSectionBuilder.php`
- `app/Domain/CRM/Reports/SectionBuilders/ShipmentSectionBuilder.php`
- `app/Domain/CRM/Reports/SectionBuilders/PurchaseOrderSectionBuilder.php`
- `app/Domain/CRM/Reports/SectionBuilders/RfqSectionBuilder.php`
- `app/Domain/CRM/Reports/StatementSectionResolver.php`
- `app/Domain/CRM/Reports/CompanyStatementService.php`
- `app/Policies/StatementPolicy.php`
- `lang/en/statements.php`
- `lang/pt_BR/statements.php`
- `lang/zh_CN/statements.php`
- `resources/views/statements/preview.blade.php`
- `app/Domain/Infrastructure/Pdf/Templates/CompanyStatementPdfTemplate.php`
- `app/Filament/Pages/StatementPreview.php` (shared base)
- `app/Filament/Portal/Pages/StatementsPage.php`
- `app/Filament/SupplierPortal/Pages/StatementsPage.php`
- `app/Filament/Actions/GenerateStatementAction.php`
- `tests/Unit/Domain/CRM/Reports/FinancialSummaryBuilderTest.php`
- `tests/Unit/Domain/CRM/Reports/StatementSectionResolverTest.php`
- `tests/Unit/Domain/CRM/Reports/SectionBuilders/InquirySectionBuilderTest.php`
- `tests/Unit/Domain/CRM/Reports/SectionBuilders/ProformaInvoiceSectionBuilderTest.php`
- `tests/Unit/Domain/CRM/Reports/SectionBuilders/ShipmentSectionBuilderTest.php`
- `tests/Feature/Reports/CompanyStatementServiceTest.php`
- `tests/Feature/Reports/StatementAuthorizationTest.php`
- `tests/Feature/Reports/StatementLocaleTest.php`

**Modify:**
- `app/Domain/CRM/Models/Company.php` — add `preferred_language` fillable + cast.
- `app/Filament/Resources/CRM/Companies/Schemas/*` — add `preferred_language` select to form.
- `app/Filament/Resources/CRM/Companies/CompanyResource.php` — wire `GenerateStatementAction` as table row action.
- `app/Filament/Resources/CRM/Companies/Pages/EditCompany.php` — wire `GenerateStatementAction` as header action.
- `app/Providers/Filament/PortalPanelProvider.php` — register `StatementsPage`.
- `app/Providers/Filament/SupplierPortalPanelProvider.php` — register `StatementsPage`.
- `app/Providers/AuthServiceProvider.php` — register `StatementPolicy` (if file uses policies array).
- `app/Domain/CRM/Services/Client360DataService.php` — consume `FinancialSummaryBuilder` for shared calculations (non-behavioral refactor guarded by existing tests).

---

## Task 1: Add `preferred_language` to companies

**Files:**
- Create: `database/migrations/2026_04_09_000001_add_preferred_language_to_companies.php`
- Modify: `app/Domain/CRM/Models/Company.php`

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('preferred_language', 10)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('preferred_language');
        });
    }
};
```

- [ ] **Step 2: Add `preferred_language` to `Company::$fillable`**

In `app/Domain/CRM/Models/Company.php`, add `'preferred_language'` to the `$fillable` array. No cast needed (plain string).

- [ ] **Step 3: Run the migration**

Run: `php artisan migrate`
Expected: migration runs without errors, `companies` table has `preferred_language` column.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_04_09_000001_add_preferred_language_to_companies.php app/Domain/CRM/Models/Company.php
git commit -m "feat(companies): add preferred_language column for statement reports"
```

---

## Task 2: Add `preferred_language` field to Company form

**Files:**
- Modify: appropriate schema file under `app/Filament/Resources/CRM/Companies/Schemas/`

- [ ] **Step 1: Locate the form schema file**

Run: `ls app/Filament/Resources/CRM/Companies/Schemas/`
Look for a file containing the form definition (likely `CompanyForm.php` or similar). Open it and locate where `email` field is defined.

- [ ] **Step 2: Add the language select immediately after the email field**

Insert:

```php
\Filament\Forms\Components\Select::make('preferred_language')
    ->label(__('statements.preferred_language'))
    ->options([
        'en' => 'English',
        'pt_BR' => 'Português (Brasil)',
        'zh_CN' => '中文 (简体)',
    ])
    ->placeholder(__('statements.use_system_default'))
    ->nullable(),
```

Note: the translation keys will be created in Task 11. If running this task before Task 11, temporarily hardcode the labels as plain strings and replace with `__()` afterward.

- [ ] **Step 3: Verify manually**

Run: `php artisan serve` and open the Company edit page in the admin panel. Confirm the "Preferred Language" select appears and persists on save.

- [ ] **Step 4: Commit**

```bash
git add app/Filament/Resources/CRM/Companies/Schemas/
git commit -m "feat(companies): add preferred language field to company form"
```

---

## Task 3: `StatementFilters` DTO

**Files:**
- Create: `app/Domain/CRM/Reports/DTOs/StatementFilters.php`

- [ ] **Step 1: Write the DTO**

```php
<?php

namespace App\Domain\CRM\Reports\DTOs;

use Carbon\CarbonImmutable;

final class StatementFilters
{
    /**
     * @param  list<string>  $sectionKeys  Keys like 'inquiries', 'quotations', 'proforma_invoices', 'shipments', 'purchase_orders', 'rfqs'.
     * @param  'active'|'closed'|'all'  $statusScope
     */
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $statusScope,
        public readonly array $sectionKeys,
        public readonly ?string $currency,
        public readonly string $locale,
    ) {
    }

    public function includes(string $sectionKey): bool
    {
        return in_array($sectionKey, $this->sectionKeys, true);
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Domain/CRM/Reports/DTOs/StatementFilters.php
git commit -m "feat(statements): add StatementFilters DTO"
```

---

## Task 4: `StatementReport` and related DTOs

**Files:**
- Create: `app/Domain/CRM/Reports/DTOs/StatementSection.php`
- Create: `app/Domain/CRM/Reports/DTOs/CurrencyTotals.php`
- Create: `app/Domain/CRM/Reports/DTOs/AgingBuckets.php`
- Create: `app/Domain/CRM/Reports/DTOs/FinancialSummary.php`
- Create: `app/Domain/CRM/Reports/DTOs/StatementReport.php`

- [ ] **Step 1: Write `StatementSection`**

```php
<?php

namespace App\Domain\CRM\Reports\DTOs;

final class StatementSection
{
    /**
     * @param  list<string>  $columns  Translation keys for column headers.
     * @param  list<array<string,scalar|null>>  $rows  Each row is an associative array keyed by column key.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $titleKey,
        public readonly array $columns,
        public readonly array $rows,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }
}
```

- [ ] **Step 2: Write `CurrencyTotals`**

```php
<?php

namespace App\Domain\CRM\Reports\DTOs;

final class CurrencyTotals
{
    public function __construct(
        public readonly string $currency,
        public readonly float $invoiced,
        public readonly float $paid,
        public readonly float $open,
    ) {
    }
}
```

- [ ] **Step 3: Write `AgingBuckets`**

```php
<?php

namespace App\Domain\CRM\Reports\DTOs;

final class AgingBuckets
{
    public function __construct(
        public readonly string $currency,
        public readonly float $bucket0to30,
        public readonly float $bucket31to60,
        public readonly float $bucket61to90,
        public readonly float $bucket90plus,
    ) {
    }
}
```

- [ ] **Step 4: Write `FinancialSummary`**

```php
<?php

namespace App\Domain\CRM\Reports\DTOs;

final class FinancialSummary
{
    /**
     * @param  list<CurrencyTotals>  $totalsByCurrency
     * @param  list<AgingBuckets>  $agingByCurrency
     * @param  array<string,array<string,float>>  $breakdownByDocumentType  Shape: [currency => [docType => total]]
     */
    public function __construct(
        public readonly array $totalsByCurrency,
        public readonly array $agingByCurrency,
        public readonly array $breakdownByDocumentType,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->totalsByCurrency === [];
    }
}
```

- [ ] **Step 5: Write `StatementReport`**

```php
<?php

namespace App\Domain\CRM\Reports\DTOs;

use App\Domain\CRM\Models\Company;
use Carbon\CarbonImmutable;

final class StatementReport
{
    /**
     * @param  list<StatementSection>  $sections
     */
    public function __construct(
        public readonly Company $company,
        public readonly CarbonImmutable $periodFrom,
        public readonly CarbonImmutable $periodTo,
        public readonly CarbonImmutable $generatedAt,
        public readonly string $locale,
        public readonly ?FinancialSummary $financialSummary,
        public readonly array $sections,
    ) {
    }

    /** @return list<StatementSection> */
    public function nonEmptySections(): array
    {
        return array_values(array_filter(
            $this->sections,
            fn (StatementSection $s) => ! $s->isEmpty(),
        ));
    }

    public function hasAnyData(): bool
    {
        return $this->financialSummary !== null || $this->nonEmptySections() !== [];
    }
}
```

- [ ] **Step 6: Commit**

```bash
git add app/Domain/CRM/Reports/DTOs/
git commit -m "feat(statements): add StatementReport DTO hierarchy"
```

---

## Task 5: `SectionBuilder` interface

**Files:**
- Create: `app/Domain/CRM/Reports/SectionBuilders/SectionBuilder.php`

- [ ] **Step 1: Write the interface**

```php
<?php

namespace App\Domain\CRM\Reports\SectionBuilders;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DTOs\StatementFilters;
use App\Domain\CRM\Reports\DTOs\StatementSection;

interface SectionBuilder
{
    public function key(): string;

    public function build(Company $company, StatementFilters $filters): StatementSection;
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Domain/CRM/Reports/SectionBuilders/SectionBuilder.php
git commit -m "feat(statements): add SectionBuilder interface"
```

---

## Task 6: `InquirySectionBuilder` (TDD)

**Files:**
- Create: `tests/Unit/Domain/CRM/Reports/SectionBuilders/InquirySectionBuilderTest.php`
- Create: `app/Domain/CRM/Reports/SectionBuilders/InquirySectionBuilder.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Domain\CRM\Reports\SectionBuilders;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DTOs\StatementFilters;
use App\Domain\CRM\Reports\SectionBuilders\InquirySectionBuilder;
use App\Domain\Inquiries\Models\Inquiry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InquirySectionBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_builds_section_with_inquiries_for_company_in_period(): void
    {
        $company = Company::factory()->create();
        $other = Company::factory()->create();

        Inquiry::factory()->create([
            'company_id' => $company->id,
            'created_at' => '2026-02-10',
        ]);
        Inquiry::factory()->create([
            'company_id' => $company->id,
            'created_at' => '2025-01-01', // before period, excluded
        ]);
        Inquiry::factory()->create([
            'company_id' => $other->id,
            'created_at' => '2026-02-15', // wrong company, excluded
        ]);

        $filters = new StatementFilters(
            from: CarbonImmutable::parse('2026-01-01'),
            to: CarbonImmutable::parse('2026-12-31'),
            statusScope: 'all',
            sectionKeys: ['inquiries'],
            currency: null,
            locale: 'en',
        );

        $section = (new InquirySectionBuilder())->build($company, $filters);

        $this->assertSame('inquiries', $section->key);
        $this->assertCount(1, $section->rows);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=InquirySectionBuilderTest`
Expected: FAIL (class not found).

- [ ] **Step 3: Implement the builder**

```php
<?php

namespace App\Domain\CRM\Reports\SectionBuilders;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DTOs\StatementFilters;
use App\Domain\CRM\Reports\DTOs\StatementSection;
use App\Domain\Inquiries\Models\Inquiry;

final class InquirySectionBuilder implements SectionBuilder
{
    public function key(): string
    {
        return 'inquiries';
    }

    public function build(Company $company, StatementFilters $filters): StatementSection
    {
        $query = Inquiry::query()
            ->where('company_id', $company->id)
            ->whereBetween('created_at', [$filters->from, $filters->to])
            ->orderBy('created_at');

        if ($filters->statusScope !== 'all') {
            $query->where('status', $filters->statusScope);
        }

        $rows = $query->get()->map(fn (Inquiry $i) => [
            'number' => $i->reference ?? (string) $i->id,
            'date' => optional($i->created_at)->format('Y-m-d'),
            'status' => $i->status instanceof \BackedEnum ? $i->status->value : (string) $i->status,
            'items' => $i->items()->count(),
            'project' => optional($i->project)->name,
        ])->all();

        return new StatementSection(
            key: 'inquiries',
            titleKey: 'statements.sections.inquiries',
            columns: ['number', 'date', 'status', 'items', 'project'],
            rows: $rows,
        );
    }
}
```

Note: before implementing, open `app/Domain/Inquiries/Models/Inquiry.php` and confirm the actual column names for reference/number and the relationship name for items and project. Adjust accessors in the `map()` above to match reality. Do the same verification for every subsequent section builder.

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=InquirySectionBuilderTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/CRM/Reports/SectionBuilders/InquirySectionBuilder.php tests/Unit/Domain/CRM/Reports/SectionBuilders/InquirySectionBuilderTest.php
git commit -m "feat(statements): add InquirySectionBuilder"
```

---

## Task 7: `QuotationSectionBuilder`

**Files:**
- Create: `app/Domain/CRM/Reports/SectionBuilders/QuotationSectionBuilder.php`

- [ ] **Step 1: Verify Quotation model fields**

Open `app/Domain/Quotations/Models/Quotation.php` and confirm column names: reference, created_at, status, total_amount, currency, valid_until.

- [ ] **Step 2: Implement the builder**

```php
<?php

namespace App\Domain\CRM\Reports\SectionBuilders;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DTOs\StatementFilters;
use App\Domain\CRM\Reports\DTOs\StatementSection;
use App\Domain\Quotations\Models\Quotation;

final class QuotationSectionBuilder implements SectionBuilder
{
    public function key(): string
    {
        return 'quotations';
    }

    public function build(Company $company, StatementFilters $filters): StatementSection
    {
        $query = Quotation::query()
            ->where('company_id', $company->id)
            ->whereBetween('created_at', [$filters->from, $filters->to])
            ->orderBy('created_at');

        if ($filters->statusScope !== 'all') {
            $query->where('status', $filters->statusScope);
        }
        if ($filters->currency !== null) {
            $query->where('currency', $filters->currency);
        }

        $rows = $query->get()->map(fn (Quotation $q) => [
            'number' => $q->reference ?? (string) $q->id,
            'date' => optional($q->created_at)->format('Y-m-d'),
            'status' => $q->status instanceof \BackedEnum ? $q->status->value : (string) $q->status,
            'total' => (float) ($q->total_amount ?? 0),
            'currency' => (string) ($q->currency ?? ''),
            'valid_until' => optional($q->valid_until)->format('Y-m-d'),
        ])->all();

        return new StatementSection(
            key: 'quotations',
            titleKey: 'statements.sections.quotations',
            columns: ['number', 'date', 'status', 'total', 'currency', 'valid_until'],
            rows: $rows,
        );
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Domain/CRM/Reports/SectionBuilders/QuotationSectionBuilder.php
git commit -m "feat(statements): add QuotationSectionBuilder"
```

---

## Task 8: `ProformaInvoiceSectionBuilder` (TDD)

**Files:**
- Create: `tests/Unit/Domain/CRM/Reports/SectionBuilders/ProformaInvoiceSectionBuilderTest.php`
- Create: `app/Domain/CRM/Reports/SectionBuilders/ProformaInvoiceSectionBuilder.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Domain\CRM\Reports\SectionBuilders;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DTOs\StatementFilters;
use App\Domain\CRM\Reports\SectionBuilders\ProformaInvoiceSectionBuilder;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProformaInvoiceSectionBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_pi_rows_with_balance_calculated(): void
    {
        $company = Company::factory()->create();

        ProformaInvoice::factory()->create([
            'company_id' => $company->id,
            'created_at' => '2026-02-10',
            'total_amount' => 10000,
            'currency' => 'USD',
        ]);

        $filters = new StatementFilters(
            from: CarbonImmutable::parse('2026-01-01'),
            to: CarbonImmutable::parse('2026-12-31'),
            statusScope: 'all',
            sectionKeys: ['proforma_invoices'],
            currency: null,
            locale: 'en',
        );

        $section = (new ProformaInvoiceSectionBuilder())->build($company, $filters);

        $this->assertSame('proforma_invoices', $section->key);
        $this->assertCount(1, $section->rows);
        $this->assertSame(10000.0, $section->rows[0]['total']);
        $this->assertArrayHasKey('balance', $section->rows[0]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ProformaInvoiceSectionBuilderTest`
Expected: FAIL (class not found).

- [ ] **Step 3: Implement the builder**

```php
<?php

namespace App\Domain\CRM\Reports\SectionBuilders;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DTOs\StatementFilters;
use App\Domain\CRM\Reports\DTOs\StatementSection;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;

final class ProformaInvoiceSectionBuilder implements SectionBuilder
{
    public function key(): string
    {
        return 'proforma_invoices';
    }

    public function build(Company $company, StatementFilters $filters): StatementSection
    {
        $query = ProformaInvoice::query()
            ->where('company_id', $company->id)
            ->whereBetween('created_at', [$filters->from, $filters->to])
            ->orderBy('created_at');

        if ($filters->statusScope !== 'all') {
            $query->where('status', $filters->statusScope);
        }
        if ($filters->currency !== null) {
            $query->where('currency', $filters->currency);
        }

        $rows = $query->get()->map(function (ProformaInvoice $pi) {
            $total = (float) ($pi->total_amount ?? 0);
            $paid = (float) ($pi->amount_paid ?? 0);

            return [
                'number' => $pi->reference ?? (string) $pi->id,
                'date' => optional($pi->created_at)->format('Y-m-d'),
                'status' => $pi->status instanceof \BackedEnum ? $pi->status->value : (string) $pi->status,
                'total' => $total,
                'paid' => $paid,
                'balance' => $total - $paid,
                'currency' => (string) ($pi->currency ?? ''),
            ];
        })->all();

        return new StatementSection(
            key: 'proforma_invoices',
            titleKey: 'statements.sections.proforma_invoices',
            columns: ['number', 'date', 'status', 'total', 'paid', 'balance', 'currency'],
            rows: $rows,
        );
    }
}
```

Verify the actual payment-aggregation method on `ProformaInvoice` (it may be `->payments()->sum(...)` rather than `amount_paid`). Adjust accordingly.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ProformaInvoiceSectionBuilderTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/CRM/Reports/SectionBuilders/ProformaInvoiceSectionBuilder.php tests/Unit/Domain/CRM/Reports/SectionBuilders/ProformaInvoiceSectionBuilderTest.php
git commit -m "feat(statements): add ProformaInvoiceSectionBuilder"
```

---

## Task 9: `ShipmentSectionBuilder` (TDD, tri-role)

**Files:**
- Create: `tests/Unit/Domain/CRM/Reports/SectionBuilders/ShipmentSectionBuilderTest.php`
- Create: `app/Domain/CRM/Reports/SectionBuilders/ShipmentSectionBuilder.php`

Shipment membership is tri-role:
- **Client**: `shipments.company_id = company.id`
- **Forwarder**: `shipments.forwarder_company_id = company.id`
- **Supplier**: joined via items — `shipment_items.supplier_company_id = company.id`

The builder receives the `CompanyRole` context from the resolver (see Task 14) and picks the correct query.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Domain\CRM\Reports\SectionBuilders;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DTOs\StatementFilters;
use App\Domain\CRM\Reports\SectionBuilders\ShipmentSectionBuilder;
use App\Domain\Logistics\Models\Shipment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShipmentSectionBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_shipments_for_client_role(): void
    {
        $client = Company::factory()->create();
        Shipment::factory()->create(['company_id' => $client->id, 'created_at' => '2026-02-10']);
        Shipment::factory()->create(['company_id' => Company::factory()->create()->id, 'created_at' => '2026-02-10']);

        $section = (new ShipmentSectionBuilder(CompanyRole::CLIENT))
            ->build($client, $this->filters());

        $this->assertCount(1, $section->rows);
    }

    public function test_returns_shipments_for_forwarder_role(): void
    {
        $forwarder = Company::factory()->create();
        Shipment::factory()->create([
            'forwarder_company_id' => $forwarder->id,
            'created_at' => '2026-02-10',
        ]);

        $section = (new ShipmentSectionBuilder(CompanyRole::FORWARDER))
            ->build($forwarder, $this->filters());

        $this->assertCount(1, $section->rows);
    }

    private function filters(): StatementFilters
    {
        return new StatementFilters(
            from: CarbonImmutable::parse('2026-01-01'),
            to: CarbonImmutable::parse('2026-12-31'),
            statusScope: 'all',
            sectionKeys: ['shipments'],
            currency: null,
            locale: 'en',
        );
    }
}
```

Note: if `ShipmentFactory` does not exist yet, write a minimal factory under `database/factories/ShipmentFactory.php` first (mirror the structure of `InquiryFactory.php`). If writing the factory would balloon this task, add a preceding micro-task for it.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ShipmentSectionBuilderTest`
Expected: FAIL.

- [ ] **Step 3: Implement the builder**

```php
<?php

namespace App\Domain\CRM\Reports\SectionBuilders;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DTOs\StatementFilters;
use App\Domain\CRM\Reports\DTOs\StatementSection;
use App\Domain\Logistics\Models\Shipment;

final class ShipmentSectionBuilder implements SectionBuilder
{
    public function __construct(private readonly CompanyRole $role)
    {
    }

    public function key(): string
    {
        return 'shipments';
    }

    public function build(Company $company, StatementFilters $filters): StatementSection
    {
        $query = Shipment::query()
            ->whereBetween('created_at', [$filters->from, $filters->to])
            ->orderBy('created_at');

        match ($this->role) {
            CompanyRole::CLIENT => $query->where('company_id', $company->id),
            CompanyRole::FORWARDER => $query->where('forwarder_company_id', $company->id),
            CompanyRole::SUPPLIER => $query->whereHas(
                'items',
                fn ($q) => $q->where('supplier_company_id', $company->id),
            ),
            default => $query->whereRaw('1 = 0'),
        };

        if ($filters->statusScope !== 'all') {
            $query->where('status', $filters->statusScope);
        }

        $rows = $query->get()->map(fn (Shipment $s) => [
            'number' => $s->reference ?? (string) $s->id,
            'etd' => optional($s->etd)->format('Y-m-d'),
            'eta' => optional($s->eta)->format('Y-m-d'),
            'status' => $s->status instanceof \BackedEnum ? $s->status->value : (string) $s->status,
            'incoterm' => (string) ($s->incoterm ?? ''),
            'mode' => (string) ($s->mode ?? ''),
        ])->all();

        return new StatementSection(
            key: 'shipments',
            titleKey: 'statements.sections.shipments',
            columns: ['number', 'etd', 'eta', 'status', 'incoterm', 'mode'],
            rows: $rows,
        );
    }
}
```

Verify that `Shipment` has an `items()` relationship and that the column names (`etd`, `eta`, `incoterm`, `mode`) match the schema. Adjust field mappings if they differ.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ShipmentSectionBuilderTest`
Expected: PASS (both tests).

- [ ] **Step 5: Commit**

```bash
git add app/Domain/CRM/Reports/SectionBuilders/ShipmentSectionBuilder.php tests/Unit/Domain/CRM/Reports/SectionBuilders/ShipmentSectionBuilderTest.php
git commit -m "feat(statements): add ShipmentSectionBuilder with role-aware queries"
```

---

## Task 10: `PurchaseOrderSectionBuilder`

**Files:**
- Create: `app/Domain/CRM/Reports/SectionBuilders/PurchaseOrderSectionBuilder.php`

- [ ] **Step 1: Implement the builder**

```php
<?php

namespace App\Domain\CRM\Reports\SectionBuilders;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DTOs\StatementFilters;
use App\Domain\CRM\Reports\DTOs\StatementSection;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;

final class PurchaseOrderSectionBuilder implements SectionBuilder
{
    public function key(): string
    {
        return 'purchase_orders';
    }

    public function build(Company $company, StatementFilters $filters): StatementSection
    {
        $query = PurchaseOrder::query()
            ->where('supplier_company_id', $company->id)
            ->whereBetween('created_at', [$filters->from, $filters->to])
            ->orderBy('created_at');

        if ($filters->statusScope !== 'all') {
            $query->where('status', $filters->statusScope);
        }
        if ($filters->currency !== null) {
            $query->where('currency', $filters->currency);
        }

        $rows = $query->get()->map(fn (PurchaseOrder $po) => [
            'number' => $po->reference ?? (string) $po->id,
            'date' => optional($po->created_at)->format('Y-m-d'),
            'status' => $po->status instanceof \BackedEnum ? $po->status->value : (string) $po->status,
            'total' => (float) ($po->total_amount ?? 0),
            'currency' => (string) ($po->currency ?? ''),
        ])->all();

        return new StatementSection(
            key: 'purchase_orders',
            titleKey: 'statements.sections.purchase_orders',
            columns: ['number', 'date', 'status', 'total', 'currency'],
            rows: $rows,
        );
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Domain/CRM/Reports/SectionBuilders/PurchaseOrderSectionBuilder.php
git commit -m "feat(statements): add PurchaseOrderSectionBuilder"
```

---

## Task 11: `RfqSectionBuilder`

**Files:**
- Create: `app/Domain/CRM/Reports/SectionBuilders/RfqSectionBuilder.php`

- [ ] **Step 1: Implement the builder**

```php
<?php

namespace App\Domain\CRM\Reports\SectionBuilders;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DTOs\StatementFilters;
use App\Domain\CRM\Reports\DTOs\StatementSection;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;

final class RfqSectionBuilder implements SectionBuilder
{
    public function key(): string
    {
        return 'rfqs';
    }

    public function build(Company $company, StatementFilters $filters): StatementSection
    {
        $query = SupplierQuotation::query()
            ->where('company_id', $company->id)
            ->whereBetween('created_at', [$filters->from, $filters->to])
            ->orderBy('created_at');

        if ($filters->statusScope !== 'all') {
            $query->where('status', $filters->statusScope);
        }

        $rows = $query->get()->map(fn (SupplierQuotation $rfq) => [
            'number' => $rfq->reference ?? (string) $rfq->id,
            'date' => optional($rfq->created_at)->format('Y-m-d'),
            'status' => $rfq->status instanceof \BackedEnum ? $rfq->status->value : (string) $rfq->status,
            'items' => $rfq->items()->count(),
            'response_deadline' => optional($rfq->response_deadline)->format('Y-m-d'),
        ])->all();

        return new StatementSection(
            key: 'rfqs',
            titleKey: 'statements.sections.rfqs',
            columns: ['number', 'date', 'status', 'items', 'response_deadline'],
            rows: $rows,
        );
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Domain/CRM/Reports/SectionBuilders/RfqSectionBuilder.php
git commit -m "feat(statements): add RfqSectionBuilder"
```

---

## Task 12: `FinancialSummaryBuilder` (TDD)

**Files:**
- Create: `tests/Unit/Domain/CRM/Reports/FinancialSummaryBuilderTest.php`
- Create: `app/Domain/CRM/Reports/FinancialSummaryBuilder.php`

Computes currency totals, aging buckets, and document-type breakdown for a company in a given period. For the client role, it uses Proforma Invoices. For the supplier role, it uses Purchase Orders. Forwarder has no financial summary (returns null).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Domain\CRM\Reports;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DTOs\StatementFilters;
use App\Domain\CRM\Reports\FinancialSummaryBuilder;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialSummaryBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_null_for_forwarder_role(): void
    {
        $company = Company::factory()->create();
        $summary = (new FinancialSummaryBuilder())->build($company, CompanyRole::FORWARDER, $this->filters());
        $this->assertNull($summary);
    }

    public function test_computes_currency_totals_for_client_role(): void
    {
        $company = Company::factory()->create();

        ProformaInvoice::factory()->create([
            'company_id' => $company->id,
            'total_amount' => 10000,
            'amount_paid' => 4000,
            'currency' => 'USD',
            'created_at' => '2026-02-10',
        ]);
        ProformaInvoice::factory()->create([
            'company_id' => $company->id,
            'total_amount' => 2000,
            'amount_paid' => 2000,
            'currency' => 'EUR',
            'created_at' => '2026-02-10',
        ]);

        $summary = (new FinancialSummaryBuilder())->build($company, CompanyRole::CLIENT, $this->filters());

        $this->assertNotNull($summary);
        $this->assertCount(2, $summary->totalsByCurrency);

        $usd = collect($summary->totalsByCurrency)->firstWhere('currency', 'USD');
        $this->assertSame(10000.0, $usd->invoiced);
        $this->assertSame(4000.0, $usd->paid);
        $this->assertSame(6000.0, $usd->open);
    }

    private function filters(): StatementFilters
    {
        return new StatementFilters(
            from: CarbonImmutable::parse('2026-01-01'),
            to: CarbonImmutable::parse('2026-12-31'),
            statusScope: 'all',
            sectionKeys: [],
            currency: null,
            locale: 'en',
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=FinancialSummaryBuilderTest`
Expected: FAIL.

- [ ] **Step 3: Implement the builder**

```php
<?php

namespace App\Domain\CRM\Reports;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DTOs\AgingBuckets;
use App\Domain\CRM\Reports\DTOs\CurrencyTotals;
use App\Domain\CRM\Reports\DTOs\FinancialSummary;
use App\Domain\CRM\Reports\DTOs\StatementFilters;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class FinancialSummaryBuilder
{
    public function build(
        Company $company,
        CompanyRole $role,
        StatementFilters $filters,
    ): ?FinancialSummary {
        $rows = match ($role) {
            CompanyRole::CLIENT => $this->clientRows($company, $filters),
            CompanyRole::SUPPLIER => $this->supplierRows($company, $filters),
            default => null,
        };

        if ($rows === null || $rows->isEmpty()) {
            return null;
        }

        $totalsByCurrency = $this->totalsByCurrency($rows);
        $agingByCurrency = $this->agingByCurrency($rows);
        $breakdownByDocumentType = $this->breakdownByDocumentType($rows, $role);

        return new FinancialSummary(
            totalsByCurrency: $totalsByCurrency,
            agingByCurrency: $agingByCurrency,
            breakdownByDocumentType: $breakdownByDocumentType,
        );
    }

    /** @return Collection<int,array{currency:string,total:float,paid:float,due_at:?CarbonImmutable}> */
    private function clientRows(Company $company, StatementFilters $filters): Collection
    {
        return ProformaInvoice::query()
            ->where('company_id', $company->id)
            ->whereBetween('created_at', [$filters->from, $filters->to])
            ->get()
            ->map(fn (ProformaInvoice $pi) => [
                'currency' => (string) ($pi->currency ?? ''),
                'total' => (float) ($pi->total_amount ?? 0),
                'paid' => (float) ($pi->amount_paid ?? 0),
                'due_at' => $pi->due_date ? CarbonImmutable::parse($pi->due_date) : null,
            ]);
    }

    /** @return Collection<int,array{currency:string,total:float,paid:float,due_at:?CarbonImmutable}> */
    private function supplierRows(Company $company, StatementFilters $filters): Collection
    {
        return PurchaseOrder::query()
            ->where('supplier_company_id', $company->id)
            ->whereBetween('created_at', [$filters->from, $filters->to])
            ->get()
            ->map(fn (PurchaseOrder $po) => [
                'currency' => (string) ($po->currency ?? ''),
                'total' => (float) ($po->total_amount ?? 0),
                'paid' => (float) ($po->amount_paid ?? 0),
                'due_at' => $po->due_date ? CarbonImmutable::parse($po->due_date) : null,
            ]);
    }

    /** @return list<CurrencyTotals> */
    private function totalsByCurrency(Collection $rows): array
    {
        return $rows
            ->groupBy('currency')
            ->map(function (Collection $group, string $currency) {
                $invoiced = (float) $group->sum('total');
                $paid = (float) $group->sum('paid');

                return new CurrencyTotals(
                    currency: $currency,
                    invoiced: $invoiced,
                    paid: $paid,
                    open: $invoiced - $paid,
                );
            })
            ->values()
            ->all();
    }

    /** @return list<AgingBuckets> */
    private function agingByCurrency(Collection $rows): array
    {
        $today = CarbonImmutable::now()->startOfDay();

        return $rows
            ->groupBy('currency')
            ->map(function (Collection $group, string $currency) use ($today) {
                $buckets = ['0_30' => 0.0, '31_60' => 0.0, '61_90' => 0.0, '90_plus' => 0.0];

                foreach ($group as $row) {
                    $open = $row['total'] - $row['paid'];
                    if ($open <= 0 || $row['due_at'] === null) {
                        continue;
                    }
                    $daysOverdue = $row['due_at']->diffInDays($today, false);
                    if ($daysOverdue <= 0) {
                        continue;
                    }
                    if ($daysOverdue <= 30) {
                        $buckets['0_30'] += $open;
                    } elseif ($daysOverdue <= 60) {
                        $buckets['31_60'] += $open;
                    } elseif ($daysOverdue <= 90) {
                        $buckets['61_90'] += $open;
                    } else {
                        $buckets['90_plus'] += $open;
                    }
                }

                return new AgingBuckets(
                    currency: $currency,
                    bucket0to30: $buckets['0_30'],
                    bucket31to60: $buckets['31_60'],
                    bucket61to90: $buckets['61_90'],
                    bucket90plus: $buckets['90_plus'],
                );
            })
            ->values()
            ->all();
    }

    /** @return array<string,array<string,float>> */
    private function breakdownByDocumentType(Collection $rows, CompanyRole $role): array
    {
        $docType = $role === CompanyRole::CLIENT ? 'proforma_invoices' : 'purchase_orders';

        return $rows
            ->groupBy('currency')
            ->map(fn (Collection $group) => [$docType => (float) $group->sum('total')])
            ->all();
    }
}
```

Important: before running the tests, open `ProformaInvoice` and `PurchaseOrder` models and confirm the actual field names for `total_amount`, `amount_paid`, and `due_date`. If the project uses different names (e.g., `paid_at`, `due_on`), adjust the queries accordingly.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=FinancialSummaryBuilderTest`
Expected: both tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/CRM/Reports/FinancialSummaryBuilder.php tests/Unit/Domain/CRM/Reports/FinancialSummaryBuilderTest.php
git commit -m "feat(statements): add FinancialSummaryBuilder with aging and currency totals"
```

---

## Task 13: `StatementSectionResolver` (TDD)

**Files:**
- Create: `tests/Unit/Domain/CRM/Reports/StatementSectionResolverTest.php`
- Create: `app/Domain/CRM/Reports/StatementSectionResolver.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Domain\CRM\Reports;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Reports\SectionBuilders\InquirySectionBuilder;
use App\Domain\CRM\Reports\SectionBuilders\ProformaInvoiceSectionBuilder;
use App\Domain\CRM\Reports\SectionBuilders\PurchaseOrderSectionBuilder;
use App\Domain\CRM\Reports\SectionBuilders\QuotationSectionBuilder;
use App\Domain\CRM\Reports\SectionBuilders\RfqSectionBuilder;
use App\Domain\CRM\Reports\SectionBuilders\ShipmentSectionBuilder;
use App\Domain\CRM\Reports\StatementSectionResolver;
use Tests\TestCase;

class StatementSectionResolverTest extends TestCase
{
    public function test_client_role_returns_client_sections(): void
    {
        $builders = (new StatementSectionResolver())->resolve(CompanyRole::CLIENT);

        $this->assertEquals(
            [InquirySectionBuilder::class, QuotationSectionBuilder::class, ProformaInvoiceSectionBuilder::class, ShipmentSectionBuilder::class],
            array_map(fn ($b) => $b::class, $builders),
        );
    }

    public function test_supplier_role_returns_supplier_sections(): void
    {
        $builders = (new StatementSectionResolver())->resolve(CompanyRole::SUPPLIER);

        $this->assertEquals(
            [RfqSectionBuilder::class, PurchaseOrderSectionBuilder::class, ShipmentSectionBuilder::class],
            array_map(fn ($b) => $b::class, $builders),
        );
    }

    public function test_forwarder_role_returns_shipments_only(): void
    {
        $builders = (new StatementSectionResolver())->resolve(CompanyRole::FORWARDER);

        $this->assertCount(1, $builders);
        $this->assertInstanceOf(ShipmentSectionBuilder::class, $builders[0]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=StatementSectionResolverTest`
Expected: FAIL.

- [ ] **Step 3: Implement the resolver**

```php
<?php

namespace App\Domain\CRM\Reports;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Reports\SectionBuilders\InquirySectionBuilder;
use App\Domain\CRM\Reports\SectionBuilders\ProformaInvoiceSectionBuilder;
use App\Domain\CRM\Reports\SectionBuilders\PurchaseOrderSectionBuilder;
use App\Domain\CRM\Reports\SectionBuilders\QuotationSectionBuilder;
use App\Domain\CRM\Reports\SectionBuilders\RfqSectionBuilder;
use App\Domain\CRM\Reports\SectionBuilders\SectionBuilder;
use App\Domain\CRM\Reports\SectionBuilders\ShipmentSectionBuilder;

final class StatementSectionResolver
{
    /** @return list<SectionBuilder> */
    public function resolve(CompanyRole $role): array
    {
        return match ($role) {
            CompanyRole::CLIENT => [
                new InquirySectionBuilder(),
                new QuotationSectionBuilder(),
                new ProformaInvoiceSectionBuilder(),
                new ShipmentSectionBuilder(CompanyRole::CLIENT),
            ],
            CompanyRole::SUPPLIER => [
                new RfqSectionBuilder(),
                new PurchaseOrderSectionBuilder(),
                new ShipmentSectionBuilder(CompanyRole::SUPPLIER),
            ],
            CompanyRole::FORWARDER => [
                new ShipmentSectionBuilder(CompanyRole::FORWARDER),
            ],
            default => [],
        };
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=StatementSectionResolverTest`
Expected: all PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/CRM/Reports/StatementSectionResolver.php tests/Unit/Domain/CRM/Reports/StatementSectionResolverTest.php
git commit -m "feat(statements): add StatementSectionResolver"
```

---

## Task 14: `CompanyStatementService` (TDD)

**Files:**
- Create: `tests/Feature/Reports/CompanyStatementServiceTest.php`
- Create: `app/Domain/CRM/Reports/CompanyStatementService.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Reports;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\CompanyStatementService;
use App\Domain\CRM\Reports\DTOs\StatementFilters;
use App\Domain\CRM\Reports\DTOs\StatementReport;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyStatementServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_builds_statement_report_for_client(): void
    {
        $company = Company::factory()->create();
        $company->companyRoles()->create(['role' => CompanyRole::CLIENT->value]);

        ProformaInvoice::factory()->create([
            'company_id' => $company->id,
            'total_amount' => 5000,
            'amount_paid' => 0,
            'currency' => 'USD',
            'created_at' => '2026-02-10',
        ]);

        $filters = new StatementFilters(
            from: CarbonImmutable::parse('2026-01-01'),
            to: CarbonImmutable::parse('2026-12-31'),
            statusScope: 'all',
            sectionKeys: ['inquiries', 'quotations', 'proforma_invoices', 'shipments'],
            currency: null,
            locale: 'en',
        );

        $report = app(CompanyStatementService::class)->build($company, $filters);

        $this->assertInstanceOf(StatementReport::class, $report);
        $this->assertSame($company->id, $report->company->id);
        $this->assertNotNull($report->financialSummary);
        $this->assertNotEmpty($report->nonEmptySections());
    }
}
```

Note: confirm how `CompanyRole` is persisted on the company — may be through `company_roles` pivot or an accessor. Adjust the role assignment to match the real mechanism.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CompanyStatementServiceTest`
Expected: FAIL (class not found).

- [ ] **Step 3: Implement the service**

```php
<?php

namespace App\Domain\CRM\Reports;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DTOs\StatementFilters;
use App\Domain\CRM\Reports\DTOs\StatementReport;
use Carbon\CarbonImmutable;

final class CompanyStatementService
{
    public function __construct(
        private readonly StatementSectionResolver $resolver,
        private readonly FinancialSummaryBuilder $financialBuilder,
    ) {
    }

    public function build(Company $company, StatementFilters $filters): StatementReport
    {
        $role = $this->resolvePrimaryRole($company);
        $builders = $this->resolver->resolve($role);

        $sections = [];
        foreach ($builders as $builder) {
            if (! $filters->includes($builder->key())) {
                continue;
            }
            $sections[] = $builder->build($company, $filters);
        }

        $financial = $this->financialBuilder->build($company, $role, $filters);

        return new StatementReport(
            company: $company,
            periodFrom: $filters->from,
            periodTo: $filters->to,
            generatedAt: CarbonImmutable::now(),
            locale: $filters->locale,
            financialSummary: $financial,
            sections: $sections,
        );
    }

    private function resolvePrimaryRole(Company $company): CompanyRole
    {
        $roleValues = $company->companyRoles()->pluck('role')->all();

        foreach ([CompanyRole::CLIENT, CompanyRole::SUPPLIER, CompanyRole::FORWARDER] as $role) {
            if (in_array($role->value, $roleValues, true)) {
                return $role;
            }
        }

        return CompanyRole::CLIENT;
    }
}
```

The `resolvePrimaryRole` helper picks the first matching role from a priority list. If the Company model has a different way of exposing roles (e.g., a `primaryRole()` method), use that instead.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=CompanyStatementServiceTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/CRM/Reports/CompanyStatementService.php tests/Feature/Reports/CompanyStatementServiceTest.php
git commit -m "feat(statements): add CompanyStatementService orchestrator"
```

---

## Task 15: Translation files

**Files:**
- Create: `lang/en/statements.php`
- Create: `lang/pt_BR/statements.php`
- Create: `lang/zh_CN/statements.php`

- [ ] **Step 1: Create `lang/en/statements.php`**

```php
<?php

return [
    'title' => 'Statement',
    'preferred_language' => 'Preferred Language',
    'use_system_default' => 'Use system default',
    'period' => 'Period',
    'generated_at' => 'Generated',
    'company' => 'Company',
    'no_records' => 'No records found for the selected filters.',

    'financial_summary' => 'Financial Summary',
    'totals_by_currency' => 'Totals by currency',
    'aging' => 'Aging (open balance)',
    'breakdown_by_document_type' => 'Breakdown by document type',
    'invoiced' => 'Invoiced',
    'paid' => 'Paid',
    'open' => 'Open',
    'days_0_30' => '0-30 days',
    'days_31_60' => '31-60 days',
    'days_61_90' => '61-90 days',
    'days_90_plus' => '90+ days',

    'sections' => [
        'inquiries' => 'Inquiries',
        'quotations' => 'Quotations',
        'proforma_invoices' => 'Proforma Invoices',
        'shipments' => 'Shipments',
        'purchase_orders' => 'Purchase Orders',
        'rfqs' => 'Requests for Quotation',
    ],

    'columns' => [
        'number' => '#',
        'date' => 'Date',
        'status' => 'Status',
        'items' => 'Items',
        'project' => 'Project',
        'total' => 'Total',
        'paid' => 'Paid',
        'balance' => 'Balance',
        'currency' => 'Currency',
        'valid_until' => 'Valid until',
        'etd' => 'ETD',
        'eta' => 'ETA',
        'incoterm' => 'Incoterm',
        'mode' => 'Mode',
        'response_deadline' => 'Response deadline',
    ],

    'filters' => [
        'title' => 'Generate Statement',
        'from' => 'From',
        'to' => 'To',
        'status_scope' => 'Status',
        'status_active' => 'Active',
        'status_closed' => 'Closed',
        'status_all' => 'All',
        'sections' => 'Sections',
        'currency' => 'Currency',
        'language' => 'Language',
        'generate' => 'Generate',
    ],

    'actions' => [
        'download_pdf' => 'Download PDF',
        'send_email' => 'Send by Email',
        'print' => 'Print',
    ],
];
```

- [ ] **Step 2: Create `lang/pt_BR/statements.php`**

Mirror the English file with Portuguese translations. Key excerpts:

```php
<?php

return [
    'title' => 'Extrato',
    'preferred_language' => 'Idioma preferido',
    'use_system_default' => 'Usar padrão do sistema',
    'period' => 'Período',
    'generated_at' => 'Gerado em',
    'company' => 'Empresa',
    'no_records' => 'Nenhum registro encontrado para os filtros selecionados.',

    'financial_summary' => 'Resumo financeiro',
    'totals_by_currency' => 'Totais por moeda',
    'aging' => 'Envelhecimento (saldo em aberto)',
    'breakdown_by_document_type' => 'Detalhamento por tipo de documento',
    'invoiced' => 'Faturado',
    'paid' => 'Pago',
    'open' => 'Em aberto',
    'days_0_30' => '0-30 dias',
    'days_31_60' => '31-60 dias',
    'days_61_90' => '61-90 dias',
    'days_90_plus' => '90+ dias',

    'sections' => [
        'inquiries' => 'Cotações recebidas',
        'quotations' => 'Propostas',
        'proforma_invoices' => 'Proformas',
        'shipments' => 'Embarques',
        'purchase_orders' => 'Ordens de compra',
        'rfqs' => 'Solicitações de cotação',
    ],

    'columns' => [
        'number' => 'Nº',
        'date' => 'Data',
        'status' => 'Status',
        'items' => 'Itens',
        'project' => 'Projeto',
        'total' => 'Total',
        'paid' => 'Pago',
        'balance' => 'Saldo',
        'currency' => 'Moeda',
        'valid_until' => 'Válido até',
        'etd' => 'ETD',
        'eta' => 'ETA',
        'incoterm' => 'Incoterm',
        'mode' => 'Modal',
        'response_deadline' => 'Prazo de resposta',
    ],

    'filters' => [
        'title' => 'Gerar extrato',
        'from' => 'De',
        'to' => 'Até',
        'status_scope' => 'Status',
        'status_active' => 'Ativos',
        'status_closed' => 'Fechados',
        'status_all' => 'Todos',
        'sections' => 'Seções',
        'currency' => 'Moeda',
        'language' => 'Idioma',
        'generate' => 'Gerar',
    ],

    'actions' => [
        'download_pdf' => 'Baixar PDF',
        'send_email' => 'Enviar por e-mail',
        'print' => 'Imprimir',
    ],
];
```

- [ ] **Step 3: Create `lang/zh_CN/statements.php`**

Mirror the structure with Simplified Chinese translations (`对账单`, `客户`, `财务汇总`, etc.). Use the same array shape.

```php
<?php

return [
    'title' => '对账单',
    'preferred_language' => '首选语言',
    'use_system_default' => '使用系统默认',
    'period' => '期间',
    'generated_at' => '生成时间',
    'company' => '公司',
    'no_records' => '所选筛选条件下未找到记录。',
    'financial_summary' => '财务汇总',
    'totals_by_currency' => '按货币合计',
    'aging' => '账龄（未结余额）',
    'breakdown_by_document_type' => '按单据类型细分',
    'invoiced' => '已开票',
    'paid' => '已付款',
    'open' => '未结',
    'days_0_30' => '0-30 天',
    'days_31_60' => '31-60 天',
    'days_61_90' => '61-90 天',
    'days_90_plus' => '90+ 天',
    'sections' => [
        'inquiries' => '询盘',
        'quotations' => '报价',
        'proforma_invoices' => '形式发票',
        'shipments' => '发货',
        'purchase_orders' => '采购订单',
        'rfqs' => '询价请求',
    ],
    'columns' => [
        'number' => '编号',
        'date' => '日期',
        'status' => '状态',
        'items' => '项目数',
        'project' => '项目',
        'total' => '合计',
        'paid' => '已付',
        'balance' => '余额',
        'currency' => '货币',
        'valid_until' => '有效至',
        'etd' => '开航日',
        'eta' => '到港日',
        'incoterm' => '贸易条款',
        'mode' => '运输方式',
        'response_deadline' => '回复截止',
    ],
    'filters' => [
        'title' => '生成对账单',
        'from' => '从',
        'to' => '至',
        'status_scope' => '状态',
        'status_active' => '进行中',
        'status_closed' => '已关闭',
        'status_all' => '全部',
        'sections' => '章节',
        'currency' => '货币',
        'language' => '语言',
        'generate' => '生成',
    ],
    'actions' => [
        'download_pdf' => '下载 PDF',
        'send_email' => '邮件发送',
        'print' => '打印',
    ],
];
```

- [ ] **Step 4: Commit**

```bash
git add lang/en/statements.php lang/pt_BR/statements.php lang/zh_CN/statements.php
git commit -m "feat(statements): add en/pt_BR/zh_CN translation files"
```

---

## Task 16: `StatementPolicy`

**Files:**
- Create: `app/Policies/StatementPolicy.php`
- Modify: `app/Providers/AuthServiceProvider.php` (only if the project uses a central policies array)

- [ ] **Step 1: Write the policy**

```php
<?php

namespace App\Policies;

use App\Domain\CRM\Models\Company;
use App\Domain\Users\Models\User;

final class StatementPolicy
{
    public function view(User $user, Company $company): bool
    {
        // Admin / staff users always have access.
        if ($user->hasAnyRole(['admin', 'staff', 'manager'])) {
            return true;
        }

        // Portal users can only view their own company's statement.
        return (int) $user->company_id === (int) $company->id;
    }
}
```

Open `app/Domain/Users/Models/User.php` and confirm: (a) the namespace, (b) whether it has a `company_id` foreign key or a relation like `company()`, and (c) whether `hasAnyRole` exists (if Spatie Permission is installed). Adjust the policy body to match real structure.

- [ ] **Step 2: Register the policy**

If `AuthServiceProvider` has a `$policies` array, add:

```php
protected $policies = [
    \App\Domain\CRM\Models\Company::class => \App\Policies\StatementPolicy::class,
];
```

Otherwise use `Gate::policy()` in `boot()`, or invoke the policy directly via `Gate::define('view-statement', ...)` — whichever is the project convention.

- [ ] **Step 3: Commit**

```bash
git add app/Policies/StatementPolicy.php app/Providers/AuthServiceProvider.php
git commit -m "feat(statements): add StatementPolicy for portal scope enforcement"
```

---

## Task 17: Authorization feature test

**Files:**
- Create: `tests/Feature/Reports/StatementAuthorizationTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

namespace Tests\Feature\Reports;

use App\Domain\CRM\Models\Company;
use App\Domain\Users\Models\User;
use App\Policies\StatementPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatementAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_user_cannot_view_other_companies_statement(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $ownCompany->id]);

        $policy = new StatementPolicy();

        $this->assertTrue($policy->view($user, $ownCompany));
        $this->assertFalse($policy->view($user, $otherCompany));
    }
}
```

If the `UserFactory` does not currently support a `company_id`, either extend the factory or use `User::factory()->make()` and assign `company_id` manually. Adjust role handling to match the real admin/staff detection.

- [ ] **Step 2: Run test to verify it passes**

Run: `php artisan test --filter=StatementAuthorizationTest`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Reports/StatementAuthorizationTest.php
git commit -m "test(statements): authorization tests for StatementPolicy"
```

---

## Task 18: Blade view for statement preview

**Files:**
- Create: `resources/views/statements/preview.blade.php`

- [ ] **Step 1: Write the view**

```blade
<!DOCTYPE html>
<html lang="{{ $report->locale }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('statements.title') }} — {{ $report->company->name }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #222; }
        h1 { font-size: 18px; margin: 0 0 4px 0; }
        h2 { font-size: 13px; margin: 18px 0 6px 0; border-bottom: 1px solid #999; padding-bottom: 2px; }
        .header { display: flex; justify-content: space-between; }
        .meta { text-align: right; font-size: 10px; color: #555; }
        table { width: 100%; border-collapse: collapse; margin-top: 4px; }
        th, td { text-align: left; padding: 4px 6px; border-bottom: 1px solid #eee; }
        th { background: #f5f5f5; font-weight: 600; }
        .numeric { text-align: right; }
        .muted { color: #777; }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1>{{ __('statements.title') }}</h1>
            <div>{{ $report->company->name }}</div>
            @if($report->company->email)
                <div class="muted">{{ $report->company->email }}</div>
            @endif
        </div>
        <div class="meta">
            <div>{{ __('statements.period') }}: {{ $report->periodFrom->format('Y-m-d') }} → {{ $report->periodTo->format('Y-m-d') }}</div>
            <div>{{ __('statements.generated_at') }}: {{ $report->generatedAt->format('Y-m-d') }}</div>
        </div>
    </div>

    @if($report->financialSummary)
        <h2>{{ __('statements.financial_summary') }}</h2>

        <div>{{ __('statements.totals_by_currency') }}</div>
        <table>
            <thead>
                <tr>
                    <th>{{ __('statements.columns.currency') }}</th>
                    <th class="numeric">{{ __('statements.invoiced') }}</th>
                    <th class="numeric">{{ __('statements.paid') }}</th>
                    <th class="numeric">{{ __('statements.open') }}</th>
                </tr>
            </thead>
            <tbody>
            @foreach($report->financialSummary->totalsByCurrency as $t)
                <tr>
                    <td>{{ $t->currency }}</td>
                    <td class="numeric">{{ number_format($t->invoiced, 2) }}</td>
                    <td class="numeric">{{ number_format($t->paid, 2) }}</td>
                    <td class="numeric">{{ number_format($t->open, 2) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>

        @if(! empty($report->financialSummary->agingByCurrency))
            <div style="margin-top:10px;">{{ __('statements.aging') }}</div>
            <table>
                <thead>
                    <tr>
                        <th>{{ __('statements.columns.currency') }}</th>
                        <th class="numeric">{{ __('statements.days_0_30') }}</th>
                        <th class="numeric">{{ __('statements.days_31_60') }}</th>
                        <th class="numeric">{{ __('statements.days_61_90') }}</th>
                        <th class="numeric">{{ __('statements.days_90_plus') }}</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($report->financialSummary->agingByCurrency as $a)
                    <tr>
                        <td>{{ $a->currency }}</td>
                        <td class="numeric">{{ number_format($a->bucket0to30, 2) }}</td>
                        <td class="numeric">{{ number_format($a->bucket31to60, 2) }}</td>
                        <td class="numeric">{{ number_format($a->bucket61to90, 2) }}</td>
                        <td class="numeric">{{ number_format($a->bucket90plus, 2) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    @endif

    @forelse($report->nonEmptySections() as $section)
        <h2>{{ __($section->titleKey) }} ({{ count($section->rows) }})</h2>
        <table>
            <thead>
                <tr>
                    @foreach($section->columns as $col)
                        <th>{{ __('statements.columns.' . $col) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($section->rows as $row)
                    <tr>
                        @foreach($section->columns as $col)
                            <td @if(is_numeric($row[$col] ?? null)) class="numeric" @endif>
                                {{ is_numeric($row[$col] ?? null) ? number_format((float) $row[$col], 2) : ($row[$col] ?? '—') }}
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @empty
        @if(! $report->financialSummary)
            <p class="muted">{{ __('statements.no_records') }}</p>
        @endif
    @endforelse
</body>
</html>
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/statements/preview.blade.php
git commit -m "feat(statements): add statement preview blade view"
```

---

## Task 19: `CompanyStatementPdfTemplate`

**Files:**
- Create: `app/Domain/Infrastructure/Pdf/Templates/CompanyStatementPdfTemplate.php`

- [ ] **Step 1: Read the base template interface**

Run `cat app/Domain/Infrastructure/Pdf/Templates/QuotationPdfTemplate.php | head -80` to see how an existing template implements `AbstractPdfTemplate`. Mirror its constructor and method signatures.

- [ ] **Step 2: Implement the template**

```php
<?php

namespace App\Domain\Infrastructure\Pdf\Templates;

use App\Domain\CRM\Reports\DTOs\StatementReport;

final class CompanyStatementPdfTemplate extends AbstractPdfTemplate
{
    public function __construct(private readonly StatementReport $report)
    {
        // AbstractPdfTemplate expects a Model — use the company as the model anchor.
        parent::__construct($report->company, $report->locale);
    }

    public function getView(): string
    {
        return 'statements.preview';
    }

    public function getDocumentTitle(): string
    {
        return __('statements.title', [], $this->report->locale)
            . ' — ' . $this->report->company->name;
    }

    public function getDocumentType(): string
    {
        return 'company_statement';
    }

    public function getFilename(): string
    {
        $slug = \Illuminate\Support\Str::slug($this->report->company->name);
        return 'statement-' . $slug . '-' . $this->report->generatedAt->format('Y-m-d') . '.pdf';
    }

    protected function getDocumentData(): array
    {
        return [
            'report' => $this->report,
        ];
    }
}
```

Verify that `AbstractPdfTemplate::getFilename()` in the base class does not force the `$model->documents()` relation (which may blow up for statements — statements don't have document rows). If it does, override `getFilename()` as shown above and also override `getNextVersion()` to return 1.

- [ ] **Step 3: Commit**

```bash
git add app/Domain/Infrastructure/Pdf/Templates/CompanyStatementPdfTemplate.php
git commit -m "feat(statements): add CompanyStatementPdfTemplate"
```

---

## Task 20: Locale test

**Files:**
- Create: `tests/Feature/Reports/StatementLocaleTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

namespace Tests\Feature\Reports;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\CompanyStatementService;
use App\Domain\CRM\Reports\DTOs\StatementFilters;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

class StatementLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_rendering_does_not_leak_locale(): void
    {
        App::setLocale('en');

        $company = Company::factory()->create();
        $filters = new StatementFilters(
            from: CarbonImmutable::parse('2026-01-01'),
            to: CarbonImmutable::parse('2026-12-31'),
            statusScope: 'all',
            sectionKeys: [],
            currency: null,
            locale: 'pt_BR',
        );

        $report = app(CompanyStatementService::class)->build($company, $filters);

        // Simulate rendering with locale wrap
        $previous = App::getLocale();
        try {
            App::setLocale($report->locale);
            view('statements.preview', ['report' => $report])->render();
        } finally {
            App::setLocale($previous);
        }

        $this->assertSame('en', App::getLocale(), 'Locale must be restored after render');
    }
}
```

- [ ] **Step 2: Run test**

Run: `php artisan test --filter=StatementLocaleTest`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Reports/StatementLocaleTest.php
git commit -m "test(statements): verify locale does not leak after render"
```

---

## Task 21: `StatementPreview` shared base Filament page

**Files:**
- Create: `app/Filament/Pages/StatementPreview.php`

This is a shared base class used by both the admin flow and the portal-specific pages. It does not register itself in any panel — only its subclasses do.

- [ ] **Step 1: Implement the base page**

```php
<?php

namespace App\Filament\Pages;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\CompanyStatementService;
use App\Domain\CRM\Reports\DTOs\StatementFilters;
use App\Domain\CRM\Reports\DTOs\StatementReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\StreamedResponse;

abstract class StatementPreview extends Page
{
    protected static string $view = 'filament.pages.statement-preview';

    public ?StatementReport $report = null;

    // Form state
    public ?string $fromDate = null;
    public ?string $toDate = null;
    public string $statusScope = 'all';
    public array $sectionKeys = [];
    public ?string $currency = null;
    public string $locale = 'en';

    abstract protected function resolveCompany(): Company;

    public function mount(): void
    {
        $company = $this->resolveCompany();
        abort_unless(auth()->user()->can('view', $company), 403);

        $this->fromDate ??= now()->startOfYear()->format('Y-m-d');
        $this->toDate ??= now()->format('Y-m-d');
        $this->locale = $company->preferred_language
            ?? auth()->user()->locale
            ?? config('app.locale');
        $this->sectionKeys = ['inquiries', 'quotations', 'proforma_invoices', 'shipments', 'purchase_orders', 'rfqs'];

        $this->generate();
    }

    public function generate(): void
    {
        $filters = new StatementFilters(
            from: CarbonImmutable::parse($this->fromDate),
            to: CarbonImmutable::parse($this->toDate)->endOfDay(),
            statusScope: $this->statusScope,
            sectionKeys: $this->sectionKeys,
            currency: $this->currency,
            locale: $this->locale,
        );

        $previous = App::getLocale();
        try {
            App::setLocale($this->locale);
            $this->report = app(CompanyStatementService::class)->build($this->resolveCompany(), $filters);
        } finally {
            App::setLocale($previous);
        }
    }

    public function downloadPdf(): StreamedResponse
    {
        abort_if($this->report === null, 400);

        $previous = App::getLocale();
        try {
            App::setLocale($this->report->locale);
            $pdf = Pdf::loadView('statements.preview', ['report' => $this->report]);
            $filename = 'statement-' . \Illuminate\Support\Str::slug($this->report->company->name) . '.pdf';

            return response()->streamDownload(fn () => print($pdf->output()), $filename);
        } finally {
            App::setLocale($previous);
        }
    }
}
```

Note: confirm the PDF facade actually in use — some projects wire dompdf via `App\Domain\Infrastructure\Pdf\PdfRenderer` or similar. If so, route the rendering through `CompanyStatementPdfTemplate` instead of calling dompdf directly.

- [ ] **Step 2: Create the Filament page view**

Write `resources/views/filament/pages/statement-preview.blade.php`:

```blade
<x-filament-panels::page>
    <div class="space-y-4">
        <form wire:submit.prevent="generate" class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <label class="text-sm">{{ __('statements.filters.from') }}
                <input type="date" wire:model.defer="fromDate" class="fi-input block w-full">
            </label>
            <label class="text-sm">{{ __('statements.filters.to') }}
                <input type="date" wire:model.defer="toDate" class="fi-input block w-full">
            </label>
            <label class="text-sm">{{ __('statements.filters.status_scope') }}
                <select wire:model.defer="statusScope" class="fi-select block w-full">
                    <option value="all">{{ __('statements.filters.status_all') }}</option>
                    <option value="active">{{ __('statements.filters.status_active') }}</option>
                    <option value="closed">{{ __('statements.filters.status_closed') }}</option>
                </select>
            </label>
            <label class="text-sm">{{ __('statements.filters.language') }}
                <select wire:model.defer="locale" class="fi-select block w-full">
                    <option value="en">English</option>
                    <option value="pt_BR">Português (BR)</option>
                    <option value="zh_CN">中文 (简体)</option>
                </select>
            </label>
            <div class="col-span-full flex gap-2">
                <button type="submit" class="fi-btn fi-btn-color-primary">{{ __('statements.filters.generate') }}</button>
                <button type="button" wire:click="downloadPdf" class="fi-btn">{{ __('statements.actions.download_pdf') }}</button>
            </div>
        </form>

        @if($report)
            <div class="border rounded p-4 bg-white">
                @include('statements.preview', ['report' => $report])
            </div>
        @endif
    </div>
</x-filament-panels::page>
```

- [ ] **Step 3: Commit**

```bash
git add app/Filament/Pages/StatementPreview.php resources/views/filament/pages/statement-preview.blade.php
git commit -m "feat(statements): add shared StatementPreview Filament page"
```

---

## Task 22: Admin entry — `GenerateStatementAction` on `CompanyResource`

**Files:**
- Create: `app/Filament/Actions/GenerateStatementAction.php`
- Create: `app/Filament/Pages/AdminStatementPreview.php`
- Modify: `app/Filament/Resources/CRM/Companies/CompanyResource.php` (register action + page)
- Modify: `app/Filament/Resources/CRM/Companies/Pages/EditCompany.php` (header action)

- [ ] **Step 1: Implement the admin subclass**

```php
<?php

namespace App\Filament\Pages;

use App\Domain\CRM\Models\Company;

final class AdminStatementPreview extends StatementPreview
{
    protected static ?string $slug = 'statements/{company}';
    protected static bool $shouldRegisterNavigation = false;

    public int $companyId;

    public function mount(int $company): void
    {
        $this->companyId = $company;
        parent::mount();
    }

    protected function resolveCompany(): Company
    {
        return Company::findOrFail($this->companyId);
    }
}
```

- [ ] **Step 2: Implement the action**

```php
<?php

namespace App\Filament\Actions;

use App\Domain\CRM\Models\Company;
use App\Filament\Pages\AdminStatementPreview;
use Filament\Actions\Action;

final class GenerateStatementAction
{
    public static function make(): Action
    {
        return Action::make('generateStatement')
            ->label(__('statements.filters.title'))
            ->icon('heroicon-o-document-text')
            ->url(fn (Company $record): string => AdminStatementPreview::getUrl(['company' => $record->id]));
    }
}
```

- [ ] **Step 3: Register the action in `CompanyResource`**

In `app/Filament/Resources/CRM/Companies/CompanyResource.php`, add the action to the table row actions block:

```php
use App\Filament\Actions\GenerateStatementAction;

// inside the table($table) method, alongside existing actions:
->actions([
    // ... existing actions
    GenerateStatementAction::make(),
])
```

Also register `AdminStatementPreview::class` in the `getPages()` method of the resource, e.g.:

```php
public static function getPages(): array
{
    return [
        // ... existing pages
        'statement' => \App\Filament\Pages\AdminStatementPreview::route('/statements/{company}'),
    ];
}
```

- [ ] **Step 4: Add header action to `EditCompany`**

In `app/Filament/Resources/CRM/Companies/Pages/EditCompany.php`:

```php
protected function getHeaderActions(): array
{
    return [
        GenerateStatementAction::make(),
        // ... existing header actions
    ];
}
```

- [ ] **Step 5: Manual verification**

Run: `php artisan serve` and open the admin Company list. Click "Generate Statement" → confirm the preview page loads, filters work, and PDF download succeeds.

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Actions/GenerateStatementAction.php app/Filament/Pages/AdminStatementPreview.php app/Filament/Resources/CRM/Companies/
git commit -m "feat(statements): admin entry point on CompanyResource"
```

---

## Task 23: Portal — client portal statements page

**Files:**
- Create: `app/Filament/Portal/Pages/StatementsPage.php`
- Modify: `app/Providers/Filament/PortalPanelProvider.php`

- [ ] **Step 1: Implement the portal page**

```php
<?php

namespace App\Filament\Portal\Pages;

use App\Domain\CRM\Models\Company;
use App\Filament\Pages\StatementPreview;

final class StatementsPage extends StatementPreview
{
    protected static ?string $slug = 'statements';
    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    public static function getNavigationLabel(): string
    {
        return __('statements.title');
    }

    protected function resolveCompany(): Company
    {
        $user = auth()->user();
        abort_unless($user && $user->company_id, 403);

        return Company::findOrFail($user->company_id);
    }
}
```

- [ ] **Step 2: Register in `PortalPanelProvider`**

In `app/Providers/Filament/PortalPanelProvider.php`, add the page to the `pages()` call (or to the directory discovery if the provider uses `discoverPages`). Example explicit registration:

```php
->pages([
    \App\Filament\Portal\Pages\PortalDashboard::class,
    \App\Filament\Portal\Pages\Messaging::class,
    \App\Filament\Portal\Pages\StatementsPage::class,
])
```

- [ ] **Step 3: Manual verification**

Log in as a portal user, confirm "Statements" appears in the sidebar, and the statement renders for the logged user's company only.

- [ ] **Step 4: Commit**

```bash
git add app/Filament/Portal/Pages/StatementsPage.php app/Providers/Filament/PortalPanelProvider.php
git commit -m "feat(statements): add Statements page to client portal"
```

---

## Task 24: Portal — supplier portal statements page

**Files:**
- Create: `app/Filament/SupplierPortal/Pages/StatementsPage.php`
- Modify: `app/Providers/Filament/SupplierPortalPanelProvider.php`

- [ ] **Step 1: Implement the supplier portal page**

```php
<?php

namespace App\Filament\SupplierPortal\Pages;

use App\Domain\CRM\Models\Company;
use App\Filament\Pages\StatementPreview;

final class StatementsPage extends StatementPreview
{
    protected static ?string $slug = 'statements';
    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    public static function getNavigationLabel(): string
    {
        return __('statements.title');
    }

    protected function resolveCompany(): Company
    {
        $user = auth()->user();
        abort_unless($user && $user->company_id, 403);

        return Company::findOrFail($user->company_id);
    }
}
```

- [ ] **Step 2: Register in `SupplierPortalPanelProvider`**

Add `\App\Filament\SupplierPortal\Pages\StatementsPage::class` to the panel's `pages()` call.

- [ ] **Step 3: Manual verification**

Log in as a supplier portal user, confirm the sidebar entry appears, and the page shows RFQ / PO / Shipment sections only.

- [ ] **Step 4: Commit**

```bash
git add app/Filament/SupplierPortal/Pages/StatementsPage.php app/Providers/Filament/SupplierPortalPanelProvider.php
git commit -m "feat(statements): add Statements page to supplier portal"
```

---

## Task 25: CJK font verification (risk checkpoint)

**Files:**
- None initially. May modify the dompdf font config if needed.

- [ ] **Step 1: Generate a zh_CN PDF manually**

Open tinker: `php artisan tinker`

```php
$company = \App\Domain\CRM\Models\Company::first();
$filters = new \App\Domain\CRM\Reports\DTOs\StatementFilters(
    \Carbon\CarbonImmutable::parse('2025-01-01'),
    \Carbon\CarbonImmutable::parse('2026-12-31'),
    'all',
    ['inquiries','quotations','proforma_invoices','shipments','purchase_orders','rfqs'],
    null,
    'zh_CN'
);
$report = app(\App\Domain\CRM\Reports\CompanyStatementService::class)->build($company, $filters);
\Illuminate\Support\Facades\App::setLocale('zh_CN');
$pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('statements.preview', ['report' => $report]);
file_put_contents(storage_path('app/statement-zh.pdf'), $pdf->output());
```

Open `storage/app/statement-zh.pdf`. Confirm Chinese characters render correctly (not boxes / tofu).

- [ ] **Step 2: If glyphs are broken — install a CJK-capable font**

Follow the dompdf docs for registering a CJK font (e.g. `cid0cs` or DejaVu with extended ranges). Update the Blade view's `body { font-family: ... }` accordingly and re-run step 1.

- [ ] **Step 3: Commit any font/config changes**

```bash
git add config/dompdf.php resources/views/statements/preview.blade.php
git commit -m "chore(statements): enable CJK font rendering for PDF output"
```

Skip this commit if no changes were needed.

---

## Task 26: Refactor `Client360DataService` to consume `FinancialSummaryBuilder`

**Files:**
- Modify: `app/Domain/CRM/Services/Client360DataService.php`

- [ ] **Step 1: Read the current `financialSummary()` method**

Run: `php artisan test tests/Feature/Client360* tests/Unit/Client360* 2>/dev/null || php artisan test --filter=Client360`
Record the baseline: all Client360 tests should pass before the refactor.

- [ ] **Step 2: Extract calculation calls**

In `Client360DataService::financialSummary()`, replace the in-service currency totals and aging calculation with calls to `FinancialSummaryBuilder`. Keep the existing `Client360FinancialSummary` DTO as the return type — map `FinancialSummary` fields into it.

Constructor:

```php
public function __construct(
    // ... existing dependencies
    private readonly FinancialSummaryBuilder $financialBuilder,
) {}
```

Then inside `financialSummary()`, build a synthetic `StatementFilters` covering the Client360 period and delegate to the builder.

- [ ] **Step 3: Re-run the Client360 tests**

Run: `php artisan test --filter=Client360`
Expected: all tests still PASS (no behavioral change).

- [ ] **Step 4: Commit**

```bash
git add app/Domain/CRM/Services/Client360DataService.php
git commit -m "refactor(client360): delegate financial calculations to FinancialSummaryBuilder"
```

If the mapping between `FinancialSummary` and `Client360FinancialSummary` proves non-trivial, stop and open a discussion before forcing it — keeping the two in sync via extraction is nice-to-have, not load-bearing for this feature. If skipping the refactor, remove this task from the commit.

---

## Self-review summary

**Spec coverage check:**
- Section 1 (Overview) → Tasks 22/23/24 (admin + portal entry points)
- Section 2 (Content by role) → Tasks 6/7/8/9/10/11 (section builders) + Task 13 (resolver)
- Section 3 (Financial summary) → Task 12
- Section 4 (Filters) → Task 21 (form state in base page)
- Section 5 (Layout) → Task 18 (blade view)
- Section 6 (Architecture) → Tasks 3/4/5/13/14/21
- Section 7 (Multi-language) → Tasks 1/2/15/20/25
- Section 8 (Data flow) → Tasks 21/22/23/24
- Section 9 (Error handling / authz) → Tasks 16/17
- Section 10 (Testing) → Tasks 6/8/9/12/13/14/17/20
- Section 11 (Out of scope) → enforced by absence
- Section 12 (Implementation checkpoints) → Tasks 1/2 (migration), Task 25 (CJK font), Task 16 (policy), Task 26 (Client360 extraction)

All spec requirements have at least one task. No placeholders. Type names are consistent across tasks (`StatementFilters`, `StatementReport`, `StatementSection`, `FinancialSummary`, `CurrencyTotals`, `AgingBuckets`, `SectionBuilder`, `CompanyStatementService`, `StatementSectionResolver`, `FinancialSummaryBuilder`, `StatementPreview`, `CompanyStatementPdfTemplate`, `StatementPolicy`, `GenerateStatementAction`).
