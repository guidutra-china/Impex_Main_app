<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Financial\Services\MonthlyCashFlowProjection;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\Settings\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonthlyCashFlowProjectionTest extends TestCase
{
    use RefreshDatabase;

    private function openItem(string $payableType, int $payableId, int $amount, string $currency, string $dueDate, PaymentScheduleStatus $status = PaymentScheduleStatus::DUE): PaymentScheduleItem
    {
        return PaymentScheduleItem::create([
            'payable_type' => $payableType,
            'payable_id' => $payableId,
            'label' => 'Installment',
            'percentage' => 100,
            'amount' => $amount,
            'currency_code' => $currency,
            'due_date' => $dueDate,
            'status' => $status,
            'is_credit' => false,
        ]);
    }

    public function test_buckets_open_items_by_due_month_in_base_currency(): void
    {
        Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'name_plural' => 'US Dollars', 'symbol' => '$', 'decimal_places' => 2, 'is_base' => true, 'is_active' => true]);

        $client = Company::factory()->create();
        $pi = ProformaInvoice::factory()->create(['company_id' => $client->id, 'currency_code' => 'USD']);
        $po = PurchaseOrder::factory()->create();

        // Current month inflow + next month outflow.
        $this->openItem(ProformaInvoice::class, $pi->id, 2_000_000, 'USD', now()->endOfMonth()->toDateString());
        $this->openItem(PurchaseOrder::class, $po->id, 1_000_000, 'USD', now()->addMonthNoOverflow()->startOfMonth()->toDateString());
        // Overdue inflow folds into the first (current) bucket.
        $this->openItem(ProformaInvoice::class, $pi->id, 500_000, 'USD', now()->subMonths(2)->toDateString(), PaymentScheduleStatus::OVERDUE);

        $result = (new MonthlyCashFlowProjection)->build();

        $this->assertCount(6, $result['labels']);
        $this->assertSame(250.0, $result['inflow'][0]);   // 200 + 50 overdue
        $this->assertSame(0.0, $result['outflow'][0]);
        $this->assertSame(100.0, $result['outflow'][1]);
        $this->assertSame(250.0, $result['net'][0]);
        $this->assertSame(-100.0, $result['net'][1]);
        $this->assertFalse($result['has_warning']);
    }

    public function test_unconvertible_currency_sets_warning(): void
    {
        Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'name_plural' => 'US Dollars', 'symbol' => '$', 'decimal_places' => 2, 'is_base' => true, 'is_active' => true]);

        $client = Company::factory()->create();
        $pi = ProformaInvoice::factory()->create(['company_id' => $client->id, 'currency_code' => 'CNY']);
        $this->openItem(ProformaInvoice::class, $pi->id, 1_000_000, 'CNY', now()->addDays(3)->toDateString());

        $result = (new MonthlyCashFlowProjection)->build();

        $this->assertTrue($result['has_warning']);
        $this->assertContains('CNY', $result['unconverted']);
        $this->assertSame([0.0, 0.0, 0.0, 0.0, 0.0, 0.0], $result['inflow']);
    }
}
