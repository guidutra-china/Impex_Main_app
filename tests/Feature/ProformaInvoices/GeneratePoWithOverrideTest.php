<?php

namespace Tests\Feature\ProformaInvoices;

use App\Domain\Financial\Actions\OverridePaymentBlocksAction;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\Settings\Enums\CalculationBase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class GeneratePoWithOverrideTest extends TestCase
{
    use RefreshDatabase;

    private User $authorizer;
    private ProformaInvoice $pi;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'override-payment-block', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'generate-purchase-orders', 'guard_name' => 'web']);

        $this->authorizer = User::factory()->create();
        $this->authorizer->givePermissionTo(['override-payment-block', 'generate-purchase-orders']);
        $this->actingAs($this->authorizer);

        $this->pi = ProformaInvoice::factory()->create(['status' => 'confirmed']);

        PaymentScheduleItem::create([
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
        ]);
    }

    public function test_override_unblocks_po_generation(): void
    {
        // Sanity check: blockers exist before override.
        $blockers = PaymentScheduleItem::blockingPurchaseOrderGeneration($this->pi);
        $this->assertCount(1, $blockers);

        // Apply override via the domain action.
        $count = (new OverridePaymentBlocksAction())->execute($this->pi, 'Client wired today.');
        $this->assertSame(1, $count);

        // Now the blocker count should be zero.
        $blockers = PaymentScheduleItem::blockingPurchaseOrderGeneration($this->pi);
        $this->assertCount(0, $blockers);
    }

    public function test_overridden_item_keeps_pending_status_after_override(): void
    {
        (new OverridePaymentBlocksAction())->execute($this->pi, 'Client wired today.');

        $item = PaymentScheduleItem::where('payable_id', $this->pi->id)->first();
        $this->assertSame(PaymentScheduleStatus::PENDING, $item->status);
        $this->assertNotNull($item->overridden_at);
        $this->assertSame($this->authorizer->id, $item->overridden_by);
    }

    public function test_new_blocking_item_added_after_override_is_not_silently_authorized(): void
    {
        (new OverridePaymentBlocksAction())->execute($this->pi, 'Authorized.');

        // Simulate reopening the PI: a new blocking item gets added later.
        PaymentScheduleItem::create([
            'payable_type'   => ProformaInvoice::class,
            'payable_id'     => $this->pi->id,
            'label'          => 'Additional 20% before production',
            'percentage'     => 20,
            'amount'         => 20000000,
            'currency_code'  => 'USD',
            'due_condition'  => CalculationBase::BEFORE_PRODUCTION->value,
            'status'         => PaymentScheduleStatus::PENDING->value,
            'is_blocking'    => true,
            'is_credit'      => false,
            'sort_order'     => 2,
        ]);

        $blockers = PaymentScheduleItem::blockingPurchaseOrderGeneration($this->pi);
        $this->assertCount(1, $blockers, 'New blocking item must NOT inherit the prior override');
    }
}
