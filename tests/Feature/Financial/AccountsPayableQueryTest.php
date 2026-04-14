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

        // Associative-array assertEquals is order-insensitive; groupBy() ordering
        // is not guaranteed across DB engines.
        $this->assertEquals(['USD' => 300_00, 'EUR' => 100_00], $report->overdueTotalsByCurrency);
        $this->assertEquals(['USD' => 500_00], $report->periodTotalsByCurrency);
        $this->assertEquals(['USD' => 800_00, 'EUR' => 100_00], $report->grandTotalsByCurrency);
    }
}
