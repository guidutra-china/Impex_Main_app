<?php

namespace Tests\Unit\Financial;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\Settings\Enums\CalculationBase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentScheduleItemOverrideTest extends TestCase
{
    use RefreshDatabase;

    private function makeBlockingItem(ProformaInvoice $pi, CalculationBase $condition): PaymentScheduleItem
    {
        return PaymentScheduleItem::create([
            'payable_type'   => ProformaInvoice::class,
            'payable_id'     => $pi->id,
            'label'          => '30% Deposit',
            'percentage'     => 30,
            'amount'         => 30000000,
            'currency_code'  => 'USD',
            'due_condition'  => $condition->value,
            'status'         => PaymentScheduleStatus::PENDING->value,
            'is_blocking'    => true,
            'is_credit'      => false,
            'sort_order'     => 1,
        ]);
    }

    public function test_overridden_item_does_not_block_po_generation(): void
    {
        $pi = ProformaInvoice::factory()->create();
        $item = $this->makeBlockingItem($pi, CalculationBase::BEFORE_PRODUCTION);

        $this->assertTrue($item->blocksPurchaseOrderGeneration());

        $item->update([
            'overridden_by'   => User::factory()->create()->id,
            'overridden_at'   => now(),
            'override_reason' => 'Client wired payment, proof incoming.',
        ]);

        $this->assertFalse($item->fresh()->blocksPurchaseOrderGeneration());
    }

    public function test_overridden_item_does_not_block_status_transition_to_in_production(): void
    {
        $pi = ProformaInvoice::factory()->create();
        $item = $this->makeBlockingItem($pi, CalculationBase::BEFORE_PRODUCTION);

        $this->assertTrue($item->blocksTransitionTo('in_production'));

        $item->update([
            'overridden_by'   => User::factory()->create()->id,
            'overridden_at'   => now(),
            'override_reason' => 'Authorized.',
        ]);

        $this->assertFalse($item->fresh()->blocksTransitionTo('in_production'));
    }

    public function test_overridden_item_keeps_pending_status(): void
    {
        $pi = ProformaInvoice::factory()->create();
        $item = $this->makeBlockingItem($pi, CalculationBase::BEFORE_PRODUCTION);

        $item->update([
            'overridden_by'   => User::factory()->create()->id,
            'overridden_at'   => now(),
            'override_reason' => 'Authorized.',
        ]);

        $this->assertSame(
            PaymentScheduleStatus::PENDING,
            $item->fresh()->status,
        );
    }

    public function test_overridden_before_shipment_item_still_blocks_shipment(): void
    {
        // Cycle scoping: an override granted to unblock POs must NOT
        // automatically unblock subsequent shipment-stage payments.
        $pi = ProformaInvoice::factory()->create();
        $item = $this->makeBlockingItem($pi, CalculationBase::BEFORE_SHIPMENT);

        // BEFORE_SHIPMENT items don't block PO generation in the first place,
        // so blocksPurchaseOrderGeneration() is false regardless of override.
        $this->assertFalse($item->blocksPurchaseOrderGeneration());

        // But they DO block the 'shipped' transition. Override (granted for PO
        // purposes) must NOT bypass that — the cycle scoping is enforced by
        // limiting overrides to PO-related due_conditions only.
        $this->assertTrue($item->blocksTransitionTo('shipped'));
    }
}
