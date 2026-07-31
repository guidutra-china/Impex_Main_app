<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Financial\Queries\AgingBucketsQuery;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgingBucketsQueryTest extends TestCase
{
    use RefreshDatabase;

    private function arItem(ProformaInvoice $pi, int $amount, string $dueDate, PaymentScheduleStatus $status = PaymentScheduleStatus::DUE): PaymentScheduleItem
    {
        return PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'label' => 'Installment',
            'percentage' => 100,
            'amount' => $amount,
            'currency_code' => 'USD',
            'due_date' => $dueDate,
            'status' => $status,
            'is_credit' => false,
        ]);
    }

    public function test_buckets_split_by_days_overdue(): void
    {
        $client = Company::factory()->create();
        $pi = ProformaInvoice::factory()->create(['company_id' => $client->id, 'currency_code' => 'USD']);

        $this->arItem($pi, 1_000_000, now()->addDays(10)->toDateString());                                   // current
        $this->arItem($pi, 2_000_000, now()->subDays(5)->toDateString(), PaymentScheduleStatus::OVERDUE);    // 1-30
        $this->arItem($pi, 3_000_000, now()->subDays(45)->toDateString(), PaymentScheduleStatus::OVERDUE);   // 31-60
        $this->arItem($pi, 4_000_000, now()->subDays(90)->toDateString(), PaymentScheduleStatus::OVERDUE);   // 60+
        $this->arItem($pi, 5_000_000, now()->toDateString());                                                // due today = current

        $buckets = AgingBucketsQuery::receivables();

        $this->assertSame(6_000_000, $buckets['current']['USD'] ?? 0);
        $this->assertSame(2_000_000, $buckets['d1_30']['USD'] ?? 0);
        $this->assertSame(3_000_000, $buckets['d31_60']['USD'] ?? 0);
        $this->assertSame(4_000_000, $buckets['d60_plus']['USD'] ?? 0);
    }

    public function test_exact_bucket_boundaries(): void
    {
        $client = Company::factory()->create();
        $pi = ProformaInvoice::factory()->create(['company_id' => $client->id, 'currency_code' => 'USD']);

        $this->arItem($pi, 1_000_000, now()->subDays(30)->toDateString(), PaymentScheduleStatus::OVERDUE); // 30 → d1_30
        $this->arItem($pi, 2_000_000, now()->subDays(31)->toDateString(), PaymentScheduleStatus::OVERDUE); // 31 → d31_60
        $this->arItem($pi, 4_000_000, now()->subDays(60)->toDateString(), PaymentScheduleStatus::OVERDUE); // 60 → d31_60
        $this->arItem($pi, 8_000_000, now()->subDays(61)->toDateString(), PaymentScheduleStatus::OVERDUE); // 61 → d60_plus

        $buckets = AgingBucketsQuery::receivables();

        $this->assertSame(1_000_000, $buckets['d1_30']['USD'] ?? 0);
        $this->assertSame(6_000_000, $buckets['d31_60']['USD'] ?? 0);
        $this->assertSame(8_000_000, $buckets['d60_plus']['USD'] ?? 0);
        $this->assertSame(0, $buckets['current']['USD'] ?? 0);
    }

    public function test_null_due_date_counts_as_current(): void
    {
        $client = Company::factory()->create();
        $pi = ProformaInvoice::factory()->create(['company_id' => $client->id, 'currency_code' => 'USD']);

        PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'label' => 'Installment',
            'percentage' => 100,
            'amount' => 1_500_000,
            'currency_code' => 'USD',
            'due_date' => null,
            'status' => PaymentScheduleStatus::DUE,
            'is_credit' => false,
        ]);

        $buckets = AgingBucketsQuery::receivables();

        $this->assertSame(1_500_000, $buckets['current']['USD'] ?? 0);
    }

    public function test_payables_side_buckets_purchase_order_items(): void
    {
        $po = PurchaseOrder::factory()->create();

        PaymentScheduleItem::create([
            'payable_type' => PurchaseOrder::class,
            'payable_id' => $po->id,
            'label' => 'Installment',
            'percentage' => 100,
            'amount' => 2_500_000,
            'currency_code' => 'USD',
            'due_date' => now()->subDays(10)->toDateString(),
            'status' => PaymentScheduleStatus::OVERDUE,
            'is_credit' => false,
        ]);

        $buckets = AgingBucketsQuery::payables();

        $this->assertSame(2_500_000, $buckets['d1_30']['USD'] ?? 0);

        // And it must not leak into the receivables side.
        $this->assertSame([], AgingBucketsQuery::receivables()['d1_30']);
    }
}
