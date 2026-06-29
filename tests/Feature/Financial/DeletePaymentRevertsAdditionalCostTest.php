<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Actions\ApprovePaymentAction;
use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reproduces: pay a client-billable Freight AdditionalCost, then delete the
 * payment. Deleting a Payment must release its allocations and revert the
 * schedule item / cost back to unpaid — otherwise the cost stays stuck at PAID
 * and can no longer be re-billed or edited (the client schedule item can't be
 * removed because it still "has" an orphaned allocation).
 */
class DeletePaymentRevertsAdditionalCostTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_a_payment_reverts_the_additional_cost_to_unpaid(): void
    {
        $client = Company::create(['name' => 'Buyer Co', 'status' => 'active']);
        $pi = ProformaInvoice::factory()->create(['company_id' => $client->id, 'currency_code' => 'USD']);

        /** @var AdditionalCost $cost */
        $cost = $pi->additionalCosts()->create([
            'cost_type' => AdditionalCostType::FREIGHT->value,
            'description' => 'Sea freight',
            'amount' => 5_000_000,
            'currency_code' => 'USD',
            'exchange_rate' => 1,
            'amount_in_document_currency' => 5_000_000,
            'billable_to' => BillableTo::CLIENT->value,
            'status' => AdditionalCostStatus::PENDING->value,
        ]);

        $scheduleItem = PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'label' => 'Freight: Sea freight',
            'percentage' => 0,
            'amount' => 5_000_000,
            'currency_code' => 'USD',
            'status' => PaymentScheduleStatus::DUE->value,
            'is_blocking' => false,
            'is_credit' => false,
            'source_type' => AdditionalCost::class,
            'source_id' => $cost->id,
            'sort_order' => 1,
        ]);

        $payment = Payment::create([
            'direction' => PaymentDirection::INBOUND,
            'company_id' => $client->id,
            'amount' => 5_000_000,
            'currency_code' => 'USD',
            'payment_date' => '2026-06-29',
            'status' => PaymentStatus::PENDING_APPROVAL,
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'payment_schedule_item_id' => $scheduleItem->id,
            'allocated_amount' => 5_000_000,
            'exchange_rate' => 1,
            'allocated_amount_in_document_currency' => 5_000_000,
        ]);

        app(ApprovePaymentAction::class)->approve($payment);

        // Sanity: payment settled the cost.
        $this->assertSame(PaymentScheduleStatus::PAID, $scheduleItem->refresh()->status);
        $this->assertSame(AdditionalCostStatus::PAID, $cost->refresh()->status);

        // Delete the payment (mirrors the AP/AR DeleteAction).
        $payment->delete();

        // The allocation must be released and the cost reverted to an unpaid,
        // editable state (no longer stuck at PAID with an orphaned allocation).
        $this->assertSame(0, $scheduleItem->allocations()->count(), 'Deleting a payment must release its allocations.');
        $this->assertNotSame(PaymentScheduleStatus::PAID, $scheduleItem->refresh()->status, 'Schedule item must not stay PAID.');
        $this->assertNotSame(AdditionalCostStatus::PAID, $cost->refresh()->status, 'AdditionalCost must not stay PAID after the payment is deleted.');
    }
}
