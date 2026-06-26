<?php

namespace Tests\Feature\PurchaseOrders;

use App\Domain\CRM\Models\Company;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DealScenarioBuilder;
use Tests\TestCase;

class PurchaseOrderPaymentStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_state_is_paid_when_fully_paid(): void
    {
        $deal = DealScenarioBuilder::make()
            ->forClient(Company::factory()->create())
            ->withPi()
            ->withPo(Company::factory()->create(), 'PO-PAID', totalMinor: 100_000_0, paidMinor: 100_000_0);

        $this->assertSame('paid', $deal->purchaseOrders[0]->fresh()->payment_state);
    }

    public function test_payment_state_is_partial_when_partially_paid(): void
    {
        $deal = DealScenarioBuilder::make()
            ->forClient(Company::factory()->create())
            ->withPi()
            ->withPo(Company::factory()->create(), 'PO-PARTIAL', totalMinor: 100_000_0, paidMinor: 40_000_0);

        $this->assertSame('partial', $deal->purchaseOrders[0]->fresh()->payment_state);
    }

    public function test_payment_state_is_pending_when_scheduled_but_unpaid(): void
    {
        $deal = DealScenarioBuilder::make()
            ->forClient(Company::factory()->create())
            ->withPi()
            ->withPo(Company::factory()->create(), 'PO-PENDING', totalMinor: 100_000_0, paidMinor: 0);

        $this->assertSame('pending', $deal->purchaseOrders[0]->fresh()->payment_state);
    }

    public function test_payment_state_is_none_without_schedule(): void
    {
        $po = PurchaseOrder::factory()->create();

        $this->assertSame('none', $po->payment_state);
    }
}
