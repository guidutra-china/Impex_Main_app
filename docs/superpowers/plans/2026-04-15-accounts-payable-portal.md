# Accounts Payable Client Portal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a "Contas a Pagar" (Accounts Payable) page to the client portal under a Finance nav group, showing upcoming payment obligations grouped by due date with period filters and KPI totals. Also move the existing `FinancialReportPage` into the same Finance nav group.

**Architecture:** A framework-agnostic query class (`AccountsPayableQuery`) builds a grouped/totaled DTO (`AccountsPayableReport`) from `PaymentScheduleItem` records. A thin Filament page (`AccountsPayablePage`) binds filter state and renders the DTO via a Blade view. Data source is restricted to items with `payable_type = ProformaInvoice` whose PI belongs to the authenticated user's `company_id` — this mirrors the existing Client360 receivables scope and avoids double-counting Shipment mirror items.

**Tech Stack:** Laravel 11, Filament v4, Livewire v3, PHPUnit + `RefreshDatabase`, Carbon, Blade.

**Spec:** `docs/superpowers/specs/2026-04-15-accounts-payable-portal-design.md`

**Key codebase facts (verified):**
- Polymorphic relation on `PaymentScheduleItem` uses `payable_type` / `payable_id` (spec incorrectly said `schedulable` — use `payable`).
- `PaymentScheduleItem` amount fields (`amount`, `paid_amount`, `remaining_amount`) are **integers in minor units** (cents). Divide by 100 for display.
- `PaymentScheduleStatus` cases: `PENDING`, `DUE`, `PAID`, `OVERDUE`, `WAIVED`. `isResolved()` returns true for `PAID` and `WAIVED`.
- Nav group key already exists: `__('navigation.groups.finance')`.
- `ProformaInvoice` has `company_id` column.
- Lang files in `lang/pt_BR/`, `lang/en/`, `lang/zh_CN/`.
- Portal Livewire tests live in `tests/Feature/Livewire/Portal/` using `RefreshDatabase` + `Tests\TestCase`.

---

## File Structure

| Path | Responsibility | New/Modified |
|------|---------------|--------------|
| `app/Domain/Financial/DataTransferObjects/AccountsPayableReport.php` | DTO returned by the query — holds overdue items, period groups, totals by currency | New |
| `app/Domain/Financial/DataTransferObjects/AccountsPayablePeriodGroup.php` | DTO for one period group (week or month) with items and subtotals | New |
| `app/Domain/Financial/Queries/AccountsPayableQuery.php` | Pure query class — inputs filters, returns DTO | New |
| `tests/Feature/Financial/AccountsPayableQueryTest.php` | Tests for the query class | New |
| `app/Filament/Portal/Pages/AccountsPayablePage.php` | Filament page binding filter state → query → view | New |
| `resources/views/filament/portal/pages/accounts-payable.blade.php` | View rendering KPIs + grouped tables | New |
| `tests/Feature/Livewire/Portal/AccountsPayablePageTest.php` | Tests for the Filament page | New |
| `lang/pt_BR/accounts_payable.php` | PT-BR translations | New |
| `lang/en/accounts_payable.php` | EN translations | New |
| `lang/zh_CN/accounts_payable.php` | ZH-CN translations | New |
| `app/Filament/Portal/Pages/FinancialReportPage.php` | Add `getNavigationGroup()` returning Finance group | Modified |

---

## Task 1: Add `getNavigationGroup()` to existing `FinancialReportPage`

**Goal:** Move the existing Financial Report into the Finance navigation group in the client portal.

**Files:**
- Modify: `app/Filament/Portal/Pages/FinancialReportPage.php`

- [ ] **Step 1: Write the failing test**

Create test file `tests/Feature/Livewire/Portal/FinancialReportPageNavigationTest.php`:

```php
<?php

namespace Tests\Feature\Livewire\Portal;

use App\Filament\Portal\Pages\FinancialReportPage;
use Tests\TestCase;

class FinancialReportPageNavigationTest extends TestCase
{
    public function test_financial_report_page_is_in_finance_navigation_group(): void
    {
        $this->assertSame(
            __('navigation.groups.finance'),
            FinancialReportPage::getNavigationGroup()
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/Livewire/Portal/FinancialReportPageNavigationTest.php`
Expected: FAIL — `getNavigationGroup()` returns `null` (Filament default) or a different value.

- [ ] **Step 3: Add `getNavigationGroup()` to FinancialReportPage**

Edit `app/Filament/Portal/Pages/FinancialReportPage.php` — add the method inside the class (e.g., right after the `$navigationSort` property, before `mount()`):

```php
    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.finance');
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/Livewire/Portal/FinancialReportPageNavigationTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Portal/Pages/FinancialReportPage.php tests/Feature/Livewire/Portal/FinancialReportPageNavigationTest.php
git commit -m "feat(portal): move Financial Report into Finance nav group"
```

---

## Task 2: Create `AccountsPayablePeriodGroup` DTO

**Goal:** Value object representing one period group (week or month) with its items and subtotals.

**Files:**
- Create: `app/Domain/Financial/DataTransferObjects/AccountsPayablePeriodGroup.php`

- [ ] **Step 1: Create the DTO class**

```php
<?php

namespace App\Domain\Financial\DataTransferObjects;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * One period bucket (week or month) of accounts payable items.
 * Totals are indexed by currency code (e.g. ['USD' => 123400, 'EUR' => 5000]).
 * Amounts are integers in minor units (cents).
 */
final class AccountsPayablePeriodGroup
{
    public function __construct(
        public readonly string $label,
        public readonly CarbonImmutable $startDate,
        public readonly CarbonImmutable $endDate,
        /** @var Collection<int, \App\Domain\Financial\Models\PaymentScheduleItem> */
        public readonly Collection $items,
        /** @var array<string, int> */
        public readonly array $totalsByCurrency,
    ) {
    }

    public function count(): int
    {
        return $this->items->count();
    }
}
```

- [ ] **Step 2: Verify the file parses**

Run: `php -l app/Domain/Financial/DataTransferObjects/AccountsPayablePeriodGroup.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add app/Domain/Financial/DataTransferObjects/AccountsPayablePeriodGroup.php
git commit -m "feat(financial): add AccountsPayablePeriodGroup DTO"
```

---

## Task 3: Create `AccountsPayableReport` DTO

**Goal:** Top-level DTO holding the full report structure returned by the query.

**Files:**
- Create: `app/Domain/Financial/DataTransferObjects/AccountsPayableReport.php`

- [ ] **Step 1: Create the DTO class**

```php
<?php

namespace App\Domain\Financial\DataTransferObjects;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Full accounts payable report returned by AccountsPayableQuery.
 * All totals are arrays keyed by ISO currency code with integer minor-unit values.
 */
final class AccountsPayableReport
{
    public function __construct(
        public readonly CarbonImmutable $dateFrom,
        public readonly CarbonImmutable $dateTo,
        public readonly string $groupingMode, // 'week' or 'month'
        /** @var Collection<int, \App\Domain\Financial\Models\PaymentScheduleItem> */
        public readonly Collection $overdueItems,
        /** @var array<string, int> */
        public readonly array $overdueTotalsByCurrency,
        /** @var Collection<int, AccountsPayablePeriodGroup> */
        public readonly Collection $periodGroups,
        /** @var array<string, int> */
        public readonly array $periodTotalsByCurrency,
        /** @var array<string, int> */
        public readonly array $grandTotalsByCurrency,
    ) {
    }

    public function hasAnyItems(): bool
    {
        return $this->overdueItems->isNotEmpty() || $this->periodGroups->isNotEmpty();
    }
}
```

- [ ] **Step 2: Verify the file parses**

Run: `php -l app/Domain/Financial/DataTransferObjects/AccountsPayableReport.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add app/Domain/Financial/DataTransferObjects/AccountsPayableReport.php
git commit -m "feat(financial): add AccountsPayableReport DTO"
```

---

## Task 4: Write first failing test for `AccountsPayableQuery` — scopes by company

**Goal:** Establish the query class interface and verify it only returns items belonging to the given company.

**Files:**
- Create: `tests/Feature/Financial/AccountsPayableQueryTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Financial\Queries\AccountsPayableQuery;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountsPayableQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_only_items_for_the_given_company(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $piA = ProformaInvoice::factory()->create(['company_id' => $companyA->id]);
        $piB = ProformaInvoice::factory()->create(['company_id' => $companyB->id]);

        $itemA = PaymentScheduleItem::factory()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $piA->id,
            'status' => PaymentScheduleStatus::PENDING,
            'due_date' => now()->addDays(10),
            'amount' => 100_00,
            'currency_code' => 'USD',
            'is_credit' => false,
        ]);
        PaymentScheduleItem::factory()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $piB->id,
            'status' => PaymentScheduleStatus::PENDING,
            'due_date' => now()->addDays(10),
            'amount' => 500_00,
            'currency_code' => 'USD',
            'is_credit' => false,
        ]);

        $report = (new AccountsPayableQuery())->run(
            companyId: $companyA->id,
            dateFrom: CarbonImmutable::now()->startOfDay(),
            dateTo: CarbonImmutable::now()->addDays(30)->endOfDay(),
            includePaid: false,
            includeOverdue: true,
        );

        $ids = $report->periodGroups->flatMap(fn ($g) => $g->items->pluck('id'))->all();
        $this->assertEquals([$itemA->id], $ids);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/Financial/AccountsPayableQueryTest.php::test_returns_only_items_for_the_given_company`
Expected: FAIL — `Class "App\Domain\Financial\Queries\AccountsPayableQuery" not found`.

- [ ] **Step 3: Do NOT commit yet — the implementation comes in Task 5.**

---

## Task 5: Implement `AccountsPayableQuery` — minimal version passing Task 4's test

**Goal:** Create the query class with just enough logic to pass the company-scoping test. Further tests in Tasks 6–10 will drive out the rest of the behavior.

**Files:**
- Create: `app/Domain/Financial/Queries/AccountsPayableQuery.php`

- [ ] **Step 1: Create the query class**

```php
<?php

namespace App\Domain\Financial\Queries;

use App\Domain\Financial\DataTransferObjects\AccountsPayablePeriodGroup;
use App\Domain\Financial\DataTransferObjects\AccountsPayableReport;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Builds the accounts payable report for a client company.
 *
 * Scope: only PaymentScheduleItem rows where payable_type is ProformaInvoice
 * and the PI belongs to the given company_id. Shipment mirror items and
 * PurchaseOrder items are excluded to avoid double counting and to match
 * the client's perspective (they only owe on PIs).
 */
final class AccountsPayableQuery
{
    public function run(
        int $companyId,
        CarbonImmutable $dateFrom,
        CarbonImmutable $dateTo,
        bool $includePaid,
        bool $includeOverdue,
    ): AccountsPayableReport {
        $piIds = ProformaInvoice::query()
            ->where('company_id', $companyId)
            ->pluck('id');

        if ($piIds->isEmpty()) {
            return $this->emptyReport($dateFrom, $dateTo);
        }

        $base = PaymentScheduleItem::query()
            ->where('payable_type', ProformaInvoice::class)
            ->whereIn('payable_id', $piIds)
            ->where('is_credit', false)
            ->with('payable');

        $openStatuses = [
            PaymentScheduleStatus::PENDING,
            PaymentScheduleStatus::DUE,
            PaymentScheduleStatus::OVERDUE,
        ];

        $today = CarbonImmutable::now()->startOfDay();

        // Overdue items (only if toggle is on): due_date < today and not resolved
        $overdueItems = collect();
        if ($includeOverdue) {
            $overdueItems = (clone $base)
                ->whereIn('status', $openStatuses)
                ->whereDate('due_date', '<', $today)
                ->orderBy('due_date')
                ->get()
                ->filter(fn (PaymentScheduleItem $item) => $item->remaining_amount > 0)
                ->values();
        }

        // Period items: due_date within [dateFrom, dateTo]
        $periodQuery = (clone $base)
            ->whereBetween('due_date', [$dateFrom->toDateString(), $dateTo->toDateString()]);

        if ($includePaid) {
            // All statuses allowed
        } else {
            $periodQuery->whereIn('status', $openStatuses);
        }

        $periodItems = $periodQuery
            ->orderBy('due_date')
            ->get()
            ->reject(
                fn (PaymentScheduleItem $item) => ! $includePaid && $item->remaining_amount <= 100
            )
            ->values();

        $groupingMode = $dateTo->diffInDays($dateFrom) > 90 ? 'month' : 'week';

        $periodGroups = $this->groupItems($periodItems, $groupingMode);

        $overdueTotals = $this->totalsByCurrency($overdueItems);
        $periodTotals = $this->totalsByCurrency($periodItems);
        $grandTotals = $this->mergeTotals($overdueTotals, $periodTotals);

        return new AccountsPayableReport(
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            groupingMode: $groupingMode,
            overdueItems: $overdueItems,
            overdueTotalsByCurrency: $overdueTotals,
            periodGroups: $periodGroups,
            periodTotalsByCurrency: $periodTotals,
            grandTotalsByCurrency: $grandTotals,
        );
    }

    private function emptyReport(CarbonImmutable $dateFrom, CarbonImmutable $dateTo): AccountsPayableReport
    {
        $groupingMode = $dateTo->diffInDays($dateFrom) > 90 ? 'month' : 'week';

        return new AccountsPayableReport(
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            groupingMode: $groupingMode,
            overdueItems: collect(),
            overdueTotalsByCurrency: [],
            periodGroups: collect(),
            periodTotalsByCurrency: [],
            grandTotalsByCurrency: [],
        );
    }

    /**
     * @param  Collection<int, PaymentScheduleItem>  $items
     * @return Collection<int, AccountsPayablePeriodGroup>
     */
    private function groupItems(Collection $items, string $mode): Collection
    {
        if ($items->isEmpty()) {
            return collect();
        }

        return $items
            ->groupBy(function (PaymentScheduleItem $item) use ($mode): string {
                $d = CarbonImmutable::parse($item->due_date);

                return $mode === 'month'
                    ? $d->format('Y-m')
                    : $d->startOfWeek()->toDateString();
            })
            ->map(function (Collection $bucket, string $key) use ($mode) {
                $first = CarbonImmutable::parse($bucket->first()->due_date);
                [$start, $end, $label] = $mode === 'month'
                    ? [
                        $first->startOfMonth(),
                        $first->endOfMonth(),
                        $first->translatedFormat('F Y'),
                    ]
                    : [
                        $first->startOfWeek(),
                        $first->endOfWeek(),
                        $first->startOfWeek()->format('d/m').' – '.$first->endOfWeek()->format('d/m'),
                    ];

                return new AccountsPayablePeriodGroup(
                    label: $label,
                    startDate: $start,
                    endDate: $end,
                    items: $bucket->values(),
                    totalsByCurrency: $this->totalsByCurrency($bucket),
                );
            })
            ->values();
    }

    /**
     * @param  Collection<int, PaymentScheduleItem>  $items
     * @return array<string, int>
     */
    private function totalsByCurrency(Collection $items): array
    {
        return $items
            ->groupBy('currency_code')
            ->map(fn (Collection $bucket) => (int) $bucket->sum('remaining_amount'))
            ->all();
    }

    /**
     * @param  array<string, int>  $a
     * @param  array<string, int>  $b
     * @return array<string, int>
     */
    private function mergeTotals(array $a, array $b): array
    {
        $result = $a;
        foreach ($b as $currency => $amount) {
            $result[$currency] = ($result[$currency] ?? 0) + $amount;
        }

        return $result;
    }
}
```

- [ ] **Step 2: Run Task 4's test and confirm it passes**

Run: `vendor/bin/phpunit tests/Feature/Financial/AccountsPayableQueryTest.php::test_returns_only_items_for_the_given_company`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add app/Domain/Financial/Queries/AccountsPayableQuery.php tests/Feature/Financial/AccountsPayableQueryTest.php
git commit -m "feat(financial): add AccountsPayableQuery with company scoping"
```

---

## Task 6: Test — period filter excludes items outside the date range

**Files:**
- Modify: `tests/Feature/Financial/AccountsPayableQueryTest.php`

- [ ] **Step 1: Add the failing test**

Append to the test class:

```php
    public function test_period_filter_only_includes_items_due_within_range(): void
    {
        $company = Company::factory()->create();
        $pi = ProformaInvoice::factory()->create(['company_id' => $company->id]);

        $inRange = PaymentScheduleItem::factory()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'status' => PaymentScheduleStatus::PENDING,
            'due_date' => now()->addDays(15),
            'amount' => 100_00,
            'currency_code' => 'USD',
            'is_credit' => false,
        ]);
        PaymentScheduleItem::factory()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'status' => PaymentScheduleStatus::PENDING,
            'due_date' => now()->addDays(60),
            'amount' => 200_00,
            'currency_code' => 'USD',
            'is_credit' => false,
        ]);

        $report = (new AccountsPayableQuery())->run(
            companyId: $company->id,
            dateFrom: CarbonImmutable::now()->startOfDay(),
            dateTo: CarbonImmutable::now()->addDays(30)->endOfDay(),
            includePaid: false,
            includeOverdue: false,
        );

        $ids = $report->periodGroups->flatMap(fn ($g) => $g->items->pluck('id'))->all();
        $this->assertEquals([$inRange->id], $ids);
    }
```

- [ ] **Step 2: Run test**

Run: `vendor/bin/phpunit tests/Feature/Financial/AccountsPayableQueryTest.php::test_period_filter_only_includes_items_due_within_range`
Expected: PASS (implementation from Task 5 already handles this).

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Financial/AccountsPayableQueryTest.php
git commit -m "test(financial): verify AccountsPayableQuery period filter"
```

---

## Task 7: Test — `includeOverdue` toggle surfaces overdue items above the period

**Files:**
- Modify: `tests/Feature/Financial/AccountsPayableQueryTest.php`

- [ ] **Step 1: Add the failing test**

Append:

```php
    public function test_include_overdue_returns_overdue_items_regardless_of_period(): void
    {
        $company = Company::factory()->create();
        $pi = ProformaInvoice::factory()->create(['company_id' => $company->id]);

        $overdue = PaymentScheduleItem::factory()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'status' => PaymentScheduleStatus::OVERDUE,
            'due_date' => now()->subDays(5),
            'amount' => 100_00,
            'currency_code' => 'USD',
            'is_credit' => false,
        ]);

        $reportIncluded = (new AccountsPayableQuery())->run(
            companyId: $company->id,
            dateFrom: CarbonImmutable::now()->startOfDay(),
            dateTo: CarbonImmutable::now()->addDays(30)->endOfDay(),
            includePaid: false,
            includeOverdue: true,
        );

        $this->assertEquals(
            [$overdue->id],
            $reportIncluded->overdueItems->pluck('id')->all()
        );

        $reportExcluded = (new AccountsPayableQuery())->run(
            companyId: $company->id,
            dateFrom: CarbonImmutable::now()->startOfDay(),
            dateTo: CarbonImmutable::now()->addDays(30)->endOfDay(),
            includePaid: false,
            includeOverdue: false,
        );

        $this->assertTrue($reportExcluded->overdueItems->isEmpty());
    }
```

- [ ] **Step 2: Run test**

Run: `vendor/bin/phpunit tests/Feature/Financial/AccountsPayableQueryTest.php::test_include_overdue_returns_overdue_items_regardless_of_period`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Financial/AccountsPayableQueryTest.php
git commit -m "test(financial): verify includeOverdue toggle behavior"
```

---

## Task 8: Test — `includePaid` toggle includes/excludes resolved items

**Files:**
- Modify: `tests/Feature/Financial/AccountsPayableQueryTest.php`

- [ ] **Step 1: Add the failing test**

Append:

```php
    public function test_include_paid_toggle_controls_paid_items(): void
    {
        $company = Company::factory()->create();
        $pi = ProformaInvoice::factory()->create(['company_id' => $company->id]);

        PaymentScheduleItem::factory()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'status' => PaymentScheduleStatus::PAID,
            'due_date' => now()->addDays(10),
            'amount' => 100_00,
            'currency_code' => 'USD',
            'is_credit' => false,
        ]);
        $openItem = PaymentScheduleItem::factory()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'status' => PaymentScheduleStatus::PENDING,
            'due_date' => now()->addDays(12),
            'amount' => 200_00,
            'currency_code' => 'USD',
            'is_credit' => false,
        ]);

        $excluded = (new AccountsPayableQuery())->run(
            companyId: $company->id,
            dateFrom: CarbonImmutable::now()->startOfDay(),
            dateTo: CarbonImmutable::now()->addDays(30)->endOfDay(),
            includePaid: false,
            includeOverdue: false,
        );
        $this->assertEquals(
            [$openItem->id],
            $excluded->periodGroups->flatMap(fn ($g) => $g->items->pluck('id'))->all()
        );

        $included = (new AccountsPayableQuery())->run(
            companyId: $company->id,
            dateFrom: CarbonImmutable::now()->startOfDay(),
            dateTo: CarbonImmutable::now()->addDays(30)->endOfDay(),
            includePaid: true,
            includeOverdue: false,
        );
        $this->assertCount(
            2,
            $included->periodGroups->flatMap(fn ($g) => $g->items)
        );
    }
```

- [ ] **Step 2: Run test**

Run: `vendor/bin/phpunit tests/Feature/Financial/AccountsPayableQueryTest.php::test_include_paid_toggle_controls_paid_items`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Financial/AccountsPayableQueryTest.php
git commit -m "test(financial): verify includePaid toggle behavior"
```

---

## Task 9: Test — grouping mode switches between week and month

**Files:**
- Modify: `tests/Feature/Financial/AccountsPayableQueryTest.php`

- [ ] **Step 1: Add the failing test**

Append:

```php
    public function test_grouping_mode_is_week_for_short_range_and_month_for_long_range(): void
    {
        $company = Company::factory()->create();

        $short = (new AccountsPayableQuery())->run(
            companyId: $company->id,
            dateFrom: CarbonImmutable::now()->startOfDay(),
            dateTo: CarbonImmutable::now()->addDays(30)->endOfDay(),
            includePaid: false,
            includeOverdue: false,
        );
        $this->assertSame('week', $short->groupingMode);

        $long = (new AccountsPayableQuery())->run(
            companyId: $company->id,
            dateFrom: CarbonImmutable::now()->startOfDay(),
            dateTo: CarbonImmutable::now()->addDays(180)->endOfDay(),
            includePaid: false,
            includeOverdue: false,
        );
        $this->assertSame('month', $long->groupingMode);
    }
```

- [ ] **Step 2: Run test**

Run: `vendor/bin/phpunit tests/Feature/Financial/AccountsPayableQueryTest.php::test_grouping_mode_is_week_for_short_range_and_month_for_long_range`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Financial/AccountsPayableQueryTest.php
git commit -m "test(financial): verify week/month grouping threshold"
```

---

## Task 10: Test — totals by currency and grand totals

**Files:**
- Modify: `tests/Feature/Financial/AccountsPayableQueryTest.php`

- [ ] **Step 1: Add the failing test**

Append:

```php
    public function test_totals_are_summed_per_currency_and_grand_total_merges_overdue_and_period(): void
    {
        $company = Company::factory()->create();
        $pi = ProformaInvoice::factory()->create(['company_id' => $company->id]);

        // Overdue: USD 300 + EUR 100
        PaymentScheduleItem::factory()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'status' => PaymentScheduleStatus::OVERDUE,
            'due_date' => now()->subDays(3),
            'amount' => 300_00,
            'currency_code' => 'USD',
            'is_credit' => false,
        ]);
        PaymentScheduleItem::factory()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'status' => PaymentScheduleStatus::OVERDUE,
            'due_date' => now()->subDays(1),
            'amount' => 100_00,
            'currency_code' => 'EUR',
            'is_credit' => false,
        ]);
        // Period: USD 500
        PaymentScheduleItem::factory()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'status' => PaymentScheduleStatus::PENDING,
            'due_date' => now()->addDays(10),
            'amount' => 500_00,
            'currency_code' => 'USD',
            'is_credit' => false,
        ]);

        $report = (new AccountsPayableQuery())->run(
            companyId: $company->id,
            dateFrom: CarbonImmutable::now()->startOfDay(),
            dateTo: CarbonImmutable::now()->addDays(30)->endOfDay(),
            includePaid: false,
            includeOverdue: true,
        );

        $this->assertSame(['USD' => 300_00, 'EUR' => 100_00], $report->overdueTotalsByCurrency);
        $this->assertSame(['USD' => 500_00], $report->periodTotalsByCurrency);
        $this->assertSame(['USD' => 800_00, 'EUR' => 100_00], $report->grandTotalsByCurrency);
    }
```

- [ ] **Step 2: Run test**

Run: `vendor/bin/phpunit tests/Feature/Financial/AccountsPayableQueryTest.php::test_totals_are_summed_per_currency_and_grand_total_merges_overdue_and_period`
Expected: PASS.

- [ ] **Step 3: Run the full query test file to catch regressions**

Run: `vendor/bin/phpunit tests/Feature/Financial/AccountsPayableQueryTest.php`
Expected: all 5 tests PASS.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Financial/AccountsPayableQueryTest.php
git commit -m "test(financial): verify totals-by-currency and grand totals"
```

---

## Task 11: Add translations

**Goal:** Create `accounts_payable.php` lang files for pt_BR, en, zh_CN.

**Files:**
- Create: `lang/pt_BR/accounts_payable.php`
- Create: `lang/en/accounts_payable.php`
- Create: `lang/zh_CN/accounts_payable.php`

- [ ] **Step 1: Create pt_BR translation**

```php
<?php

return [
    'title' => 'Contas a Pagar',
    'subtitle' => 'Pagamentos pendentes por período',
    'filters' => [
        'period' => 'Período',
        'preset_7_days' => 'Próx. 7 dias',
        'preset_30_days' => 'Próx. 30 dias',
        'preset_90_days' => 'Próx. 90 dias',
        'preset_this_month' => 'Este mês',
        'preset_next_month' => 'Próximo mês',
        'preset_custom' => 'Customizado',
        'date_from' => 'De',
        'date_to' => 'Até',
        'include_overdue' => 'Incluir vencidas',
        'include_paid' => 'Incluir pagas',
    ],
    'kpis' => [
        'overdue' => 'Vencido',
        'period' => 'No Período',
        'total' => 'Total a Pagar',
    ],
    'groups' => [
        'overdue' => 'Vencidas',
        'items_count' => ':count itens',
    ],
    'columns' => [
        'due_date' => 'Vencimento',
        'reference' => 'Referência',
        'description' => 'Descrição',
        'currency' => 'Moeda',
        'amount' => 'Valor',
        'paid' => 'Pago',
        'remaining' => 'Saldo',
        'status' => 'Status',
    ],
    'empty_state' => 'Nenhuma conta a pagar no período selecionado.',
];
```

- [ ] **Step 2: Create en translation**

```php
<?php

return [
    'title' => 'Accounts Payable',
    'subtitle' => 'Pending payments by period',
    'filters' => [
        'period' => 'Period',
        'preset_7_days' => 'Next 7 days',
        'preset_30_days' => 'Next 30 days',
        'preset_90_days' => 'Next 90 days',
        'preset_this_month' => 'This month',
        'preset_next_month' => 'Next month',
        'preset_custom' => 'Custom',
        'date_from' => 'From',
        'date_to' => 'To',
        'include_overdue' => 'Include overdue',
        'include_paid' => 'Include paid',
    ],
    'kpis' => [
        'overdue' => 'Overdue',
        'period' => 'In Period',
        'total' => 'Total Due',
    ],
    'groups' => [
        'overdue' => 'Overdue',
        'items_count' => ':count items',
    ],
    'columns' => [
        'due_date' => 'Due date',
        'reference' => 'Reference',
        'description' => 'Description',
        'currency' => 'Currency',
        'amount' => 'Amount',
        'paid' => 'Paid',
        'remaining' => 'Balance',
        'status' => 'Status',
    ],
    'empty_state' => 'No payables in the selected period.',
];
```

- [ ] **Step 3: Create zh_CN translation**

```php
<?php

return [
    'title' => '应付账款',
    'subtitle' => '按期间的待付款项',
    'filters' => [
        'period' => '期间',
        'preset_7_days' => '未来 7 天',
        'preset_30_days' => '未来 30 天',
        'preset_90_days' => '未来 90 天',
        'preset_this_month' => '本月',
        'preset_next_month' => '下月',
        'preset_custom' => '自定义',
        'date_from' => '从',
        'date_to' => '至',
        'include_overdue' => '包含逾期',
        'include_paid' => '包含已付',
    ],
    'kpis' => [
        'overdue' => '逾期',
        'period' => '本期',
        'total' => '应付总额',
    ],
    'groups' => [
        'overdue' => '逾期',
        'items_count' => ':count 项',
    ],
    'columns' => [
        'due_date' => '到期日',
        'reference' => '参考',
        'description' => '说明',
        'currency' => '货币',
        'amount' => '金额',
        'paid' => '已付',
        'remaining' => '余额',
        'status' => '状态',
    ],
    'empty_state' => '所选期间内无应付款项。',
];
```

- [ ] **Step 4: Verify syntax**

Run: `php -l lang/pt_BR/accounts_payable.php && php -l lang/en/accounts_payable.php && php -l lang/zh_CN/accounts_payable.php`
Expected: three "No syntax errors detected" lines.

- [ ] **Step 5: Commit**

```bash
git add lang/pt_BR/accounts_payable.php lang/en/accounts_payable.php lang/zh_CN/accounts_payable.php
git commit -m "feat(i18n): add accounts_payable translations for pt_BR, en, zh_CN"
```

---

## Task 12: Create the Blade view for the page

**Goal:** Render KPI cards, overdue section, and period groups from the `AccountsPayableReport` DTO.

**Files:**
- Create: `resources/views/filament/portal/pages/accounts-payable.blade.php`

- [ ] **Step 1: Create the view**

```blade
<x-filament-panels::page>
    {{-- Filter form --}}
    <form wire:submit.prevent="applyFilters" class="fi-section-content p-6 space-y-4 bg-white rounded-xl shadow-sm dark:bg-gray-900">
        <div class="grid grid-cols-1 md:grid-cols-6 gap-3">
            <label class="md:col-span-2">
                <span class="block text-sm font-medium">{{ __('accounts_payable.filters.period') }}</span>
                <select wire:model.live="preset" class="mt-1 block w-full rounded-md border-gray-300 dark:bg-gray-800">
                    <option value="7">{{ __('accounts_payable.filters.preset_7_days') }}</option>
                    <option value="30">{{ __('accounts_payable.filters.preset_30_days') }}</option>
                    <option value="90">{{ __('accounts_payable.filters.preset_90_days') }}</option>
                    <option value="this_month">{{ __('accounts_payable.filters.preset_this_month') }}</option>
                    <option value="next_month">{{ __('accounts_payable.filters.preset_next_month') }}</option>
                    <option value="custom">{{ __('accounts_payable.filters.preset_custom') }}</option>
                </select>
            </label>

            @if ($preset === 'custom')
                <label>
                    <span class="block text-sm font-medium">{{ __('accounts_payable.filters.date_from') }}</span>
                    <input type="date" wire:model.live="customFrom" class="mt-1 block w-full rounded-md border-gray-300 dark:bg-gray-800" />
                </label>
                <label>
                    <span class="block text-sm font-medium">{{ __('accounts_payable.filters.date_to') }}</span>
                    <input type="date" wire:model.live="customTo" class="mt-1 block w-full rounded-md border-gray-300 dark:bg-gray-800" />
                </label>
            @endif

            <label class="flex items-center gap-2 mt-6">
                <input type="checkbox" wire:model.live="includeOverdue" />
                <span>{{ __('accounts_payable.filters.include_overdue') }}</span>
            </label>

            <label class="flex items-center gap-2 mt-6">
                <input type="checkbox" wire:model.live="includePaid" />
                <span>{{ __('accounts_payable.filters.include_paid') }}</span>
            </label>
        </div>
    </form>

    {{-- KPI cards --}}
    @php $report = $this->getReport(); @endphp
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <x-filament::section>
            <div class="text-xs uppercase text-gray-500">{{ __('accounts_payable.kpis.overdue') }}</div>
            @foreach ($report->overdueTotalsByCurrency as $currency => $amount)
                <div class="text-lg font-semibold text-danger-600">{{ $currency }} {{ number_format($amount / 100, 2) }}</div>
            @endforeach
            @if (empty($report->overdueTotalsByCurrency))
                <div class="text-lg font-semibold">—</div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs uppercase text-gray-500">{{ __('accounts_payable.kpis.period') }}</div>
            @foreach ($report->periodTotalsByCurrency as $currency => $amount)
                <div class="text-lg font-semibold">{{ $currency }} {{ number_format($amount / 100, 2) }}</div>
            @endforeach
            @if (empty($report->periodTotalsByCurrency))
                <div class="text-lg font-semibold">—</div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs uppercase text-gray-500">{{ __('accounts_payable.kpis.total') }}</div>
            @foreach ($report->grandTotalsByCurrency as $currency => $amount)
                <div class="text-lg font-semibold">{{ $currency }} {{ number_format($amount / 100, 2) }}</div>
            @endforeach
            @if (empty($report->grandTotalsByCurrency))
                <div class="text-lg font-semibold">—</div>
            @endif
        </x-filament::section>
    </div>

    {{-- Overdue section --}}
    @if ($report->overdueItems->isNotEmpty())
        <x-filament::section>
            <x-slot name="heading">
                <span class="text-danger-600">🔴 {{ __('accounts_payable.groups.overdue') }}</span>
                <span class="text-sm text-gray-500">
                    ({{ trans_choice('accounts_payable.groups.items_count', $report->overdueItems->count(), ['count' => $report->overdueItems->count()]) }})
                </span>
            </x-slot>
            @include('filament.portal.pages.partials.accounts-payable-table', ['items' => $report->overdueItems])
        </x-filament::section>
    @endif

    {{-- Period groups --}}
    @forelse ($report->periodGroups as $group)
        <x-filament::section>
            <x-slot name="heading">
                📅 {{ $group->label }}
                <span class="text-sm text-gray-500">
                    ({{ trans_choice('accounts_payable.groups.items_count', $group->count(), ['count' => $group->count()]) }})
                </span>
            </x-slot>
            @include('filament.portal.pages.partials.accounts-payable-table', ['items' => $group->items])
        </x-filament::section>
    @empty
        @if ($report->overdueItems->isEmpty())
            <x-filament::section>
                <p class="text-center text-gray-500 py-8">{{ __('accounts_payable.empty_state') }}</p>
            </x-filament::section>
        @endif
    @endforelse
</x-filament-panels::page>
```

- [ ] **Step 2: Create the shared row-table partial**

Create `resources/views/filament/portal/pages/partials/accounts-payable-table.blade.php`:

```blade
<table class="w-full text-sm">
    <thead class="bg-gray-50 dark:bg-gray-800">
        <tr class="text-left">
            <th class="p-2">{{ __('accounts_payable.columns.due_date') }}</th>
            <th class="p-2">{{ __('accounts_payable.columns.reference') }}</th>
            <th class="p-2">{{ __('accounts_payable.columns.description') }}</th>
            <th class="p-2">{{ __('accounts_payable.columns.currency') }}</th>
            <th class="p-2 text-right">{{ __('accounts_payable.columns.amount') }}</th>
            <th class="p-2 text-right">{{ __('accounts_payable.columns.paid') }}</th>
            <th class="p-2 text-right">{{ __('accounts_payable.columns.remaining') }}</th>
            <th class="p-2">{{ __('accounts_payable.columns.status') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($items as $item)
            <tr class="border-t border-gray-100 dark:border-gray-700">
                <td class="p-2">{{ $item->due_date?->format('d/m/Y') }}</td>
                <td class="p-2">
                    @if ($item->payable)
                        <a href="{{ \App\Filament\Portal\Resources\ProformaInvoiceResource::getUrl('view', ['record' => $item->payable]) }}" class="text-primary-600 underline">
                            {{ $item->payable->reference ?? '—' }}
                        </a>
                    @else
                        —
                    @endif
                </td>
                <td class="p-2">{{ $item->label }}</td>
                <td class="p-2">{{ $item->currency_code }}</td>
                <td class="p-2 text-right">{{ number_format($item->amount / 100, 2) }}</td>
                <td class="p-2 text-right">{{ number_format($item->paid_amount / 100, 2) }}</td>
                <td class="p-2 text-right font-medium">{{ number_format($item->remaining_amount / 100, 2) }}</td>
                <td class="p-2">
                    <span class="inline-flex px-2 py-0.5 text-xs rounded bg-gray-100 dark:bg-gray-800">
                        {{ $item->status->getLabel() }}
                    </span>
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
```

- [ ] **Step 3: Commit**

```bash
git add resources/views/filament/portal/pages/accounts-payable.blade.php resources/views/filament/portal/pages/partials/accounts-payable-table.blade.php
git commit -m "feat(portal): add accounts-payable view and table partial"
```

---

## Task 13: Write failing test for `AccountsPayablePage` page load

**Files:**
- Create: `tests/Feature/Livewire/Portal/AccountsPayablePageTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

namespace Tests\Feature\Livewire\Portal;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Filament\Portal\Pages\AccountsPayablePage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AccountsPayablePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_loads_for_authenticated_user_with_company(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->actingAs($user);

        $pi = ProformaInvoice::factory()->create(['company_id' => $company->id]);
        PaymentScheduleItem::factory()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'status' => PaymentScheduleStatus::PENDING,
            'due_date' => now()->addDays(10),
            'amount' => 123_45,
            'currency_code' => 'USD',
            'is_credit' => false,
        ]);

        Livewire::test(AccountsPayablePage::class)
            ->assertOk()
            ->assertSee('USD');
    }
}
```

- [ ] **Step 2: Run test**

Run: `vendor/bin/phpunit tests/Feature/Livewire/Portal/AccountsPayablePageTest.php::test_page_loads_for_authenticated_user_with_company`
Expected: FAIL — `Class "App\Filament\Portal\Pages\AccountsPayablePage" not found`.

- [ ] **Step 3: Do NOT commit yet — implementation in Task 14.**

---

## Task 14: Implement `AccountsPayablePage`

**Files:**
- Create: `app/Filament/Portal/Pages/AccountsPayablePage.php`

- [ ] **Step 1: Create the page class**

```php
<?php

namespace App\Filament\Portal\Pages;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\DataTransferObjects\AccountsPayableReport;
use App\Domain\Financial\Queries\AccountsPayableQuery;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;

class AccountsPayablePage extends Page
{
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 55;

    protected static ?string $slug = 'accounts-payable';

    protected string $view = 'filament.portal.pages.accounts-payable';

    public string $preset = '30';

    public ?string $customFrom = null;

    public ?string $customTo = null;

    public bool $includeOverdue = true;

    public bool $includePaid = false;

    public static function getNavigationLabel(): string
    {
        return __('accounts_payable.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.finance');
    }

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('accounts_payable.title');
    }

    public function mount(): void
    {
        $this->resolveCompany(); // 403 early if user has no company
    }

    public function getReport(): AccountsPayableReport
    {
        $company = $this->resolveCompany();
        [$from, $to] = $this->resolveDateRange();

        return (new AccountsPayableQuery())->run(
            companyId: $company->id,
            dateFrom: $from,
            dateTo: $to,
            includePaid: $this->includePaid,
            includeOverdue: $this->includeOverdue,
        );
    }

    protected function resolveCompany(): Company
    {
        $user = auth()->user();
        abort_unless($user && $user->company_id, 403);

        return Company::findOrFail($user->company_id);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    protected function resolveDateRange(): array
    {
        $today = CarbonImmutable::now()->startOfDay();

        return match ($this->preset) {
            '7' => [$today, $today->addDays(7)->endOfDay()],
            '90' => [$today, $today->addDays(90)->endOfDay()],
            'this_month' => [$today->startOfMonth(), $today->endOfMonth()],
            'next_month' => [$today->addMonth()->startOfMonth(), $today->addMonth()->endOfMonth()],
            'custom' => [
                $this->customFrom ? CarbonImmutable::parse($this->customFrom)->startOfDay() : $today,
                $this->customTo ? CarbonImmutable::parse($this->customTo)->endOfDay() : $today->addDays(30)->endOfDay(),
            ],
            default => [$today, $today->addDays(30)->endOfDay()], // '30'
        };
    }
}
```

- [ ] **Step 2: Run Task 13's test and confirm it passes**

Run: `vendor/bin/phpunit tests/Feature/Livewire/Portal/AccountsPayablePageTest.php::test_page_loads_for_authenticated_user_with_company`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add app/Filament/Portal/Pages/AccountsPayablePage.php tests/Feature/Livewire/Portal/AccountsPayablePageTest.php
git commit -m "feat(portal): add AccountsPayablePage Filament page"
```

---

## Task 15: Test — users without company get 403

**Files:**
- Modify: `tests/Feature/Livewire/Portal/AccountsPayablePageTest.php`

- [ ] **Step 1: Add the failing test**

Append:

```php
    public function test_user_without_company_receives_403(): void
    {
        $user = User::factory()->create(['company_id' => null]);
        $this->actingAs($user);

        Livewire::test(AccountsPayablePage::class)
            ->assertStatus(403);
    }
```

- [ ] **Step 2: Run test**

Run: `vendor/bin/phpunit tests/Feature/Livewire/Portal/AccountsPayablePageTest.php::test_user_without_company_receives_403`
Expected: PASS (already enforced by `resolveCompany()` in `mount()`).

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Livewire/Portal/AccountsPayablePageTest.php
git commit -m "test(portal): verify AccountsPayablePage blocks users without company"
```

---

## Task 16: Test — company isolation (user A cannot see company B data)

**Files:**
- Modify: `tests/Feature/Livewire/Portal/AccountsPayablePageTest.php`

- [ ] **Step 1: Add the failing test**

Append:

```php
    public function test_user_a_does_not_see_company_b_items(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $userA = User::factory()->create(['company_id' => $companyA->id]);
        $this->actingAs($userA);

        $piB = ProformaInvoice::factory()->create(['company_id' => $companyB->id]);
        PaymentScheduleItem::factory()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $piB->id,
            'status' => PaymentScheduleStatus::PENDING,
            'due_date' => now()->addDays(5),
            'amount' => 999_99,
            'currency_code' => 'EUR',
            'is_credit' => false,
        ]);

        Livewire::test(AccountsPayablePage::class)
            ->assertOk()
            ->assertDontSee('EUR')
            ->assertDontSee('999.99');
    }
```

- [ ] **Step 2: Run test**

Run: `vendor/bin/phpunit tests/Feature/Livewire/Portal/AccountsPayablePageTest.php::test_user_a_does_not_see_company_b_items`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Livewire/Portal/AccountsPayablePageTest.php
git commit -m "test(portal): verify AccountsPayablePage company isolation"
```

---

## Task 17: Test — preset and toggles change rendered data

**Files:**
- Modify: `tests/Feature/Livewire/Portal/AccountsPayablePageTest.php`

- [ ] **Step 1: Add the failing test**

Append:

```php
    public function test_preset_and_toggles_change_rendered_data(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->actingAs($user);

        $pi = ProformaInvoice::factory()->create(['company_id' => $company->id]);
        // Paid item in next 30 days — hidden when includePaid = false
        PaymentScheduleItem::factory()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'status' => PaymentScheduleStatus::PAID,
            'due_date' => now()->addDays(5),
            'amount' => 777_00,
            'currency_code' => 'USD',
            'is_credit' => false,
        ]);

        Livewire::test(AccountsPayablePage::class)
            ->set('preset', '30')
            ->set('includePaid', false)
            ->assertDontSee('777.00')
            ->set('includePaid', true)
            ->assertSee('777.00');
    }
```

- [ ] **Step 2: Run test**

Run: `vendor/bin/phpunit tests/Feature/Livewire/Portal/AccountsPayablePageTest.php::test_preset_and_toggles_change_rendered_data`
Expected: PASS.

- [ ] **Step 3: Run the full page test file**

Run: `vendor/bin/phpunit tests/Feature/Livewire/Portal/AccountsPayablePageTest.php`
Expected: 4 tests PASS.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Livewire/Portal/AccountsPayablePageTest.php
git commit -m "test(portal): verify preset and paid toggle change rendered data"
```

---

## Task 18: Full-suite regression check

**Goal:** Make sure no unrelated test broke during this work.

- [ ] **Step 1: Run relevant test buckets**

Run:

```bash
vendor/bin/phpunit tests/Feature/Financial/AccountsPayableQueryTest.php \
    tests/Feature/Livewire/Portal/ \
    tests/Unit/
```

Expected: all tests PASS.

- [ ] **Step 2: If any test fails, diagnose and fix before proceeding. Do not mark this task complete while tests are red.**

- [ ] **Step 3: (If nothing needed to change) no commit required; the plan is complete.**

---

## Self-Review (internal — already applied)

- **Spec coverage:** ✅ Navigation placement (Task 1, 14), presets + custom range (Task 14), toggles (Tasks 7, 8, 15–17), KPIs (Task 12), overdue section (Task 7, 12), week/month grouping (Task 9), DTO separation (Tasks 2, 3, 5), company isolation (Tasks 4, 16), 403 for users without company (Task 15), translations (Task 11), table columns (Task 12).
- **Spec→plan corrections applied:** spec said `schedulable` — plan uses the correct `payable` relation. Spec implied PO/Shipment items might be shown — plan narrows to PI only (matches Client360 and avoids double counting via Shipment mirrors); this is noted explicitly in the Architecture header.
- **Export (PDF/Excel):** explicitly out of scope per spec.
- **No placeholders:** every step has full code or exact command.
- **Type consistency:** DTO property names (`overdueItems`, `periodGroups`, `grandTotalsByCurrency`, `groupingMode`) are used identically in query, page, and view.

---

## Execution Handoff

Plan complete and saved to `docs/superpowers/plans/2026-04-15-accounts-payable-portal.md`. Two execution options:

1. **Subagent-Driven (recommended)** — fresh subagent per task, review between tasks, fast iteration.
2. **Inline Execution** — execute tasks in this session with checkpoints for review.

Which approach?
