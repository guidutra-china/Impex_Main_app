<?php

namespace Tests\Feature\PurchaseOrders;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\Settings\Enums\CalculationBase;
use App\Filament\Resources\PurchaseOrders\Pages\EditPurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * BEFORE_PRODUCTION blocking payments must gate the PO confirmed -> in_production
 * transition. Historically only EditPurchaseOrder::beforeSave() ran the check, and
 * that path is dead code (the status field is disabled on the edit form). The real
 * paths — the header transitionStatus action and the table status action — ran
 * TransitionStatusAction directly, bypassing the payment gate entirely.
 */
class PaymentBlockGuardTest extends TestCase
{
    use RefreshDatabase;

    private function confirmedPoWithBlockingPayment(): PurchaseOrder
    {
        $pi = ProformaInvoice::factory()->create();
        $supplier = Company::factory()->create();

        $po = PurchaseOrder::create([
            'reference' => 'PO-GUARD-'.uniqid(),
            'proforma_invoice_id' => $pi->id,
            'supplier_company_id' => $supplier->id,
            'currency_code' => 'USD',
            'status' => PurchaseOrderStatus::CONFIRMED->value,
        ]);

        PaymentScheduleItem::create([
            'payable_type' => PurchaseOrder::class,
            'payable_id' => $po->id,
            'label' => '50% before production',
            'percentage' => 50,
            'amount' => 50000000,
            'currency_code' => 'USD',
            'due_condition' => CalculationBase::BEFORE_PRODUCTION->value,
            'status' => PaymentScheduleStatus::PENDING->value,
            'is_blocking' => true,
            'is_credit' => false,
            'sort_order' => 1,
        ]);

        return $po;
    }

    private function actAsAdmin(): void
    {
        $admin = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $admin->id ? true : null);
        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');
    }

    public function test_table_action_cannot_advance_to_in_production_with_unpaid_blocking_payment(): void
    {
        $this->actAsAdmin();
        $po = $this->confirmedPoWithBlockingPayment();

        Livewire::test(ListPurchaseOrders::class)
            ->callTableAction('transition_to_in_production', $po);

        $this->assertSame(
            PurchaseOrderStatus::CONFIRMED->value,
            $po->fresh()->status->value,
            'Table action must not advance a PO past an unpaid BEFORE_PRODUCTION payment.'
        );
    }

    public function test_header_action_cannot_advance_to_in_production_with_unpaid_blocking_payment(): void
    {
        $this->actAsAdmin();
        $po = $this->confirmedPoWithBlockingPayment();

        Livewire::test(EditPurchaseOrder::class, ['record' => $po->id])
            ->callAction('transitionStatus', data: ['new_status' => PurchaseOrderStatus::IN_PRODUCTION->value]);

        $this->assertSame(
            PurchaseOrderStatus::CONFIRMED->value,
            $po->fresh()->status->value,
            'Header action must not advance a PO past an unpaid BEFORE_PRODUCTION payment.'
        );
    }

    public function test_transition_succeeds_once_blocking_payment_is_paid(): void
    {
        $this->actAsAdmin();
        $po = $this->confirmedPoWithBlockingPayment();

        $po->paymentScheduleItems()->update(['status' => PaymentScheduleStatus::PAID->value]);

        Livewire::test(ListPurchaseOrders::class)
            ->callTableAction('transition_to_in_production', $po);

        $this->assertSame(
            PurchaseOrderStatus::IN_PRODUCTION->value,
            $po->fresh()->status->value,
            'Transition must proceed once the blocking payment is resolved.'
        );
    }
}
