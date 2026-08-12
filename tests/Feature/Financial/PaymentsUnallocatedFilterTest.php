<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Filament\Resources\Finance\AccountsPayable\Pages\ListAccountsPayable;
use App\Filament\Resources\Finance\AccountsReceivable\Pages\ListAccountsReceivable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Filtro "Com saldo não alocado" nas listas de Pagamentos e Recebimentos:
 * unallocated_amount é accessor, então o filtro refaz a conta em SQL.
 */
class PaymentsUnallocatedFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        \Filament\Facades\Filament::setCurrentPanel('admin');

        $user = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $user->id ? true : null);
        $this->actingAs($user);
    }

    private function payment(PaymentDirection $direction, int $amount): Payment
    {
        return Payment::create([
            'direction' => $direction,
            'company_id' => Company::factory()->create()->id,
            'amount' => $amount,
            'currency_code' => 'USD',
            'payment_date' => now()->toDateString(),
            'status' => PaymentStatus::APPROVED,
        ]);
    }

    private function allocate(Payment $payment, Model $payable, int $amount): void
    {
        $scheduleItem = PaymentScheduleItem::create([
            'payable_type' => get_class($payable),
            'payable_id' => $payable->getKey(),
            'label' => 'Balance',
            'percentage' => 100,
            'amount' => $amount,
            'currency_code' => 'USD',
            'status' => PaymentScheduleStatus::DUE->value,
            'is_blocking' => false,
            'is_credit' => false,
            'sort_order' => 1,
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'payment_schedule_item_id' => $scheduleItem->id,
            'allocated_amount' => $amount,
            'exchange_rate' => 1,
            'allocated_amount_in_document_currency' => $amount,
        ]);
    }

    public function test_payables_filter_keeps_only_payments_with_unallocated_balance(): void
    {
        $po = PurchaseOrder::factory()->create();

        $untouched = $this->payment(PaymentDirection::OUTBOUND, 10_000_000);

        $partial = $this->payment(PaymentDirection::OUTBOUND, 10_000_000);
        $this->allocate($partial, $po, 4_000_000);

        $fullyAllocated = $this->payment(PaymentDirection::OUTBOUND, 10_000_000);
        $this->allocate($fullyAllocated, $po, 10_000_000);

        Livewire::test(ListAccountsPayable::class)
            ->assertCanSeeTableRecords([$untouched, $partial, $fullyAllocated])
            ->filterTable('unallocated', true)
            ->assertCanSeeTableRecords([$untouched, $partial])
            ->assertCanNotSeeTableRecords([$fullyAllocated]);
    }

    public function test_receivables_filter_keeps_only_payments_with_unallocated_balance(): void
    {
        $pi = ProformaInvoice::factory()->create();

        $untouched = $this->payment(PaymentDirection::INBOUND, 5_000_000);

        $fullyAllocated = $this->payment(PaymentDirection::INBOUND, 5_000_000);
        $this->allocate($fullyAllocated, $pi, 5_000_000);

        Livewire::test(ListAccountsReceivable::class)
            ->filterTable('unallocated', true)
            ->assertCanSeeTableRecords([$untouched])
            ->assertCanNotSeeTableRecords([$fullyAllocated]);
    }
}
