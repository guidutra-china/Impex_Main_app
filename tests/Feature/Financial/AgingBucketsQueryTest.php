<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Financial\Queries\AgingBucketsQuery;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
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
}
