<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Filament\Resources\Finance\AccountsPayable\Pages\ListAccountsPayable;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Coluna "Alocado Para": um documento por linha, com o total alocado a ele.
 * A soma das linhas tem de bater com a coluna "Alocado" do mesmo pagamento.
 */
class PayablesAllocatedToColumnTest extends TestCase
{
    use RefreshDatabase;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $user = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $user->id ? true : null);
        $this->actingAs($user);

        $this->payment = Payment::create([
            'direction' => PaymentDirection::OUTBOUND,
            'company_id' => Company::factory()->create()->id,
            'amount' => 200_000_000,
            'currency_code' => 'USD',
            'payment_date' => now()->toDateString(),
            'status' => PaymentStatus::APPROVED,
        ]);
    }

    private function allocate(PurchaseOrder $po, int $amount, int $sortOrder = 1): void
    {
        $scheduleItem = PaymentScheduleItem::create([
            'payable_type' => PurchaseOrder::class,
            'payable_id' => $po->id,
            'label' => 'Parcela '.$sortOrder,
            'percentage' => 50,
            'amount' => $amount,
            'currency_code' => 'USD',
            'status' => PaymentScheduleStatus::DUE->value,
            'is_blocking' => false,
            'is_credit' => false,
            'sort_order' => $sortOrder,
        ]);

        PaymentAllocation::create([
            'payment_id' => $this->payment->id,
            'payment_schedule_item_id' => $scheduleItem->id,
            'allocated_amount' => $amount,
            'exchange_rate' => 1,
            'allocated_amount_in_document_currency' => $amount,
        ]);
    }

    private function entries(): array
    {
        $method = new \ReflectionMethod(
            \App\Filament\Resources\Finance\AccountsPayable\Tables\PayablesTable::class,
            'resolveAllocatedToEntries',
        );

        return $method->invoke(null, $this->payment->fresh())->all();
    }

    public function test_one_line_per_purchase_order_with_its_own_total(): void
    {
        $first = PurchaseOrder::factory()->create(['supplier_invoice_number' => 'F0225-12027']);
        $second = PurchaseOrder::factory()->create(['supplier_invoice_number' => 'F0225-12028']);

        $this->allocate($first, 30_000_000);
        $this->allocate($second, 12_500_000);

        $entries = collect($this->entries())->keyBy('reference');

        $this->assertCount(2, $entries);
        $this->assertSame(30_000_000, $entries[$first->reference]['total']);
        $this->assertSame(12_500_000, $entries[$second->reference]['total']);
        $this->assertSame('F0225-12027', $entries[$first->reference]['extra']);
    }

    public function test_several_installments_of_the_same_po_collapse_into_one_line(): void
    {
        $po = PurchaseOrder::factory()->create();

        $this->allocate($po, 20_000_000, 1);
        $this->allocate($po, 5_000_000, 2);

        $entries = $this->entries();

        $this->assertCount(1, $entries, 'o mesmo documento não pode repetir na coluna');
        $this->assertSame(25_000_000, $entries[0]['total']);
    }

    public function test_line_totals_add_up_to_the_allocated_column(): void
    {
        $this->allocate(PurchaseOrder::factory()->create(), 30_000_000);
        $this->allocate(PurchaseOrder::factory()->create(), 12_500_000, 2);

        $sum = collect($this->entries())->sum('total');

        $this->assertSame($this->payment->fresh()->allocated_total, $sum);
    }

    public function test_list_renders_one_row_per_document_with_the_amount(): void
    {
        $po = PurchaseOrder::factory()->create(['supplier_invoice_number' => 'F0225-12027']);
        $other = PurchaseOrder::factory()->create();

        $this->allocate($po, 30_000_000);
        $this->allocate($other, 12_500_000, 2);

        $html = Livewire::test(ListAccountsPayable::class)->html();

        // Cada documento numa <div> própria, com o valor ao lado.
        $this->assertStringContainsString(e($po->reference), $html);
        $this->assertStringContainsString('F0225-12027', $html);
        $this->assertStringContainsString(Money::format(30_000_000), $html);
        $this->assertStringContainsString(Money::format(12_500_000), $html);
    }
}
