<?php

namespace Tests\Unit\Financial;

use App\Domain\Financial\Actions\OverridePaymentBlocksAction;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\Settings\Enums\CalculationBase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OverridePaymentBlocksActionTest extends TestCase
{
    use RefreshDatabase;

    private OverridePaymentBlocksAction $action;
    private User $authorizer;
    private ProformaInvoice $pi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new OverridePaymentBlocksAction();
        $this->authorizer = User::factory()->create();
        $this->pi = ProformaInvoice::factory()->create();
        $this->actingAs($this->authorizer);
    }

    private function makeItem(array $overrides = []): PaymentScheduleItem
    {
        return PaymentScheduleItem::create(array_merge([
            'payable_type'   => ProformaInvoice::class,
            'payable_id'     => $this->pi->id,
            'label'          => '30% Deposit',
            'percentage'     => 30,
            'amount'         => 30000000,
            'currency_code'  => 'USD',
            'due_condition'  => CalculationBase::BEFORE_PRODUCTION->value,
            'status'         => PaymentScheduleStatus::PENDING->value,
            'is_blocking'    => true,
            'is_credit'      => false,
            'sort_order'     => 1,
        ], $overrides));
    }

    public function test_marks_all_blocking_items_with_user_and_reason(): void
    {
        $a = $this->makeItem(['label' => 'Stage A']);
        $b = $this->makeItem(['label' => 'Stage B', 'due_condition' => CalculationBase::ORDER_DATE->value]);

        $count = $this->action->execute($this->pi, 'Client wired today.');

        $this->assertSame(2, $count);

        foreach ([$a, $b] as $item) {
            $fresh = $item->fresh();
            $this->assertSame($this->authorizer->id, $fresh->overridden_by);
            $this->assertNotNull($fresh->overridden_at);
            $this->assertSame('Client wired today.', $fresh->override_reason);
        }
    }

    public function test_skips_already_paid_items(): void
    {
        $paid = $this->makeItem(['status' => PaymentScheduleStatus::PAID->value]);

        $count = $this->action->execute($this->pi, 'Reason.');

        $this->assertSame(0, $count);
        $this->assertNull($paid->fresh()->overridden_at);
    }

    public function test_skips_already_waived_items(): void
    {
        $waived = $this->makeItem(['status' => PaymentScheduleStatus::WAIVED->value]);

        $count = $this->action->execute($this->pi, 'Reason.');

        $this->assertSame(0, $count);
        $this->assertNull($waived->fresh()->overridden_at);
    }

    public function test_skips_non_blocking_items(): void
    {
        $nonBlocking = $this->makeItem(['is_blocking' => false]);

        $count = $this->action->execute($this->pi, 'Reason.');

        $this->assertSame(0, $count);
        $this->assertNull($nonBlocking->fresh()->overridden_at);
    }

    public function test_skips_credit_items(): void
    {
        $credit = $this->makeItem(['is_credit' => true]);

        $count = $this->action->execute($this->pi, 'Reason.');

        $this->assertSame(0, $count);
        $this->assertNull($credit->fresh()->overridden_at);
    }

    public function test_skips_before_shipment_items(): void
    {
        // Cycle scoping: BEFORE_SHIPMENT items are NOT in scope for PO override.
        $shipment = $this->makeItem(['due_condition' => CalculationBase::BEFORE_SHIPMENT->value]);

        $count = $this->action->execute($this->pi, 'Reason.');

        $this->assertSame(0, $count);
        $this->assertNull($shipment->fresh()->overridden_at);
    }
}
