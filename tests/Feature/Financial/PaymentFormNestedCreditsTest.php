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
use App\Domain\Settings\Models\Currency;
use App\Filament\Resources\Finance\AccountsPayable\Pages\CreateAccountsPayable;
use App\Filament\Resources\Finance\AccountsPayable\Pages\EditAccountsPayable;
use App\Filament\Resources\Finance\AccountsPayable\Schemas\PayableForm;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Caso real (2026-09-11): wire de 61.598,49 para PO-67 e PO-71 com descontos
 * de fábrica. O crédito ficava em outro quadro e não debitava a alocação —
 * o guard barrava com "Cash allocations exceed the payment amount". Agora o
 * crédito vive na linha da parcela e o dinheiro nasce líquido.
 */
class PaymentFormNestedCreditsTest extends TestCase
{
    use RefreshDatabase;

    private Company $supplier;

    private PurchaseOrder $po71;

    private PaymentScheduleItem $installment71;

    private PaymentScheduleItem $discount71;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $user = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $user->id ? true : null);
        $this->actingAs($user);

        Currency::create([
            'code' => 'USD', 'name' => 'US Dollar', 'name_plural' => 'US Dollars',
            'symbol' => '$', 'decimal_places' => 2, 'is_base' => true, 'is_active' => true,
        ]);

        $this->supplier = Company::create(['name' => 'Shandong Luslud', 'status' => 'active']);
        $this->supplier->companyRoles()->create(['role' => 'supplier']);

        $this->po71 = PurchaseOrder::factory()->create(['supplier_company_id' => $this->supplier->id, 'currency_code' => 'USD']);
        $this->installment71 = $this->installment($this->po71, '70% — Before Shipment', 47840.10);
        $this->discount71 = $this->credit($this->po71, 'Discount: Factory discount 3%', 2050.29);
    }

    private function installment(PurchaseOrder $po, string $label, float $amount): PaymentScheduleItem
    {
        return PaymentScheduleItem::create([
            'payable_type' => PurchaseOrder::class,
            'payable_id' => $po->id,
            'label' => $label,
            'percentage' => 70,
            'amount' => Money::toMinor($amount),
            'currency_code' => 'USD',
            'status' => PaymentScheduleStatus::DUE->value,
            'is_blocking' => false,
            'is_credit' => false,
            'sort_order' => 1,
        ]);
    }

    private function credit(PurchaseOrder $po, string $label, float $amount): PaymentScheduleItem
    {
        return PaymentScheduleItem::create([
            'payable_type' => PurchaseOrder::class,
            'payable_id' => $po->id,
            'label' => $label,
            'percentage' => 0,
            'amount' => Money::toMinor($amount),
            'currency_code' => 'USD',
            'status' => PaymentScheduleStatus::PENDING->value,
            'is_blocking' => false,
            'is_credit' => true,
            'sort_order' => 9,
        ]);
    }

    /**
     * Preenche o cabeçalho e as parcelas como o usuário faz: só escolhe a
     * parcela; a pré-carga aplica o crédito do documento e sugere o líquido.
     *
     * @param  list<PaymentScheduleItem>  $items
     */
    private function createForm(string $amount, array $items): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(CreateAccountsPayable::class)
            ->fillForm([
                'company_id' => $this->supplier->id,
                'currency_code' => 'USD',
                'amount' => $amount,
                'payment_date' => '2026-09-11',
                'allocations' => array_map(fn (PaymentScheduleItem $item) => [
                    'payment_schedule_item_id' => $item->id,
                ], $items),
            ]);
    }

    /** @return list<array<string, mixed>> linhas de alocação no estado do formulário, indexadas de 0 */
    private function rows(\Livewire\Features\SupportTesting\Testable $component): array
    {
        return array_values($component->get('data.allocations') ?? []);
    }

    private function rowKey(\Livewire\Features\SupportTesting\Testable $component, int $index): string
    {
        return (string) array_keys($component->get('data.allocations'))[$index];
    }

    public function test_the_po_discount_is_offered_as_an_own_credit_of_its_installment(): void
    {
        $own = PayableForm::ownCreditsFor($this->installment71, $this->supplier->id, PaymentDirection::OUTBOUND);

        $this->assertSame([['id' => $this->discount71->id, 'available' => Money::toMinor(2050.29)]], $own);
    }

    public function test_choosing_the_installment_applies_its_discount_and_suggests_the_net_cash(): void
    {
        $component = $this->createForm('45789.81', [$this->installment71]);
        $row = $this->rows($component)[0];

        $credits = array_values($row['credits']);
        $this->assertCount(1, $credits);
        $this->assertSame($this->discount71->id, (int) $credits[0]['credit_schedule_item_id']);
        $this->assertSame('2050.29', (string) $credits[0]['credit_amount']);
        $this->assertEqualsWithDelta(45789.81, (float) $row['allocated_amount'], 0.001);
    }

    public function test_net_cash_plus_nested_credit_is_accepted_and_persisted_as_cash_and_credit_rows(): void
    {
        $this->createForm('45789.81', [$this->installment71])
            ->call('create')
            ->assertHasNoFormErrors();

        $payment = Payment::latest('id')->firstOrFail();
        $allocations = PaymentAllocation::where('payment_id', $payment->id)->get();

        $cash = $allocations->firstWhere('credit_schedule_item_id', null);
        $creditRow = $allocations->firstWhere('credit_schedule_item_id', $this->discount71->id);

        $this->assertNotNull($cash);
        $this->assertSame(Money::toMinor(45789.81), $cash->allocated_amount_in_document_currency);
        $this->assertSame($this->installment71->id, $cash->payment_schedule_item_id);

        $this->assertNotNull($creditRow);
        $this->assertSame(Money::toMinor(2050.29), $creditRow->allocated_amount_in_document_currency);
        $this->assertSame($this->installment71->id, $creditRow->payment_schedule_item_id);
        $this->assertSame(0, $creditRow->allocated_amount);

        // Crédito totalmente consumido fica reservado.
        $this->assertSame(PaymentScheduleStatus::PAID, $this->discount71->fresh()->status);
    }

    public function test_full_cash_plus_credit_on_the_same_row_is_still_blocked_by_the_guard(): void
    {
        // A armadilha antiga, forçada à mão: dinheiro cheio por cima do crédito.
        $component = $this->createForm('47840.10', [$this->installment71]);
        $component
            ->set('data.allocations.'.$this->rowKey($component, 0).'.allocated_amount', '47840.10')
            ->call('create')
            ->assertHasFormErrors(['allocations']);

        $this->assertSame(0, Payment::count());
    }

    public function test_an_installment_fully_covered_by_its_credit_needs_no_cash(): void
    {
        $po67 = PurchaseOrder::factory()->create(['supplier_company_id' => $this->supplier->id, 'currency_code' => 'USD']);
        $installment67 = $this->installment($po67, '70% — Before Shipment', 449.52);
        $discount67 = $this->credit($po67, 'Discount: Factory discount 2%', 449.52);

        $component = $this->createForm('45789.81', [$this->installment71, $installment67]);

        $this->assertEqualsWithDelta(0.0, (float) $this->rows($component)[1]['allocated_amount'], 0.001);

        $component->call('create')->assertHasNoFormErrors();

        $payment = Payment::latest('id')->firstOrFail();

        $this->assertSame(1, PaymentAllocation::where('payment_id', $payment->id)->whereNull('credit_schedule_item_id')->count());
        $this->assertSame(2, PaymentAllocation::where('payment_id', $payment->id)->whereNotNull('credit_schedule_item_id')->count());
        $this->assertSame(
            Money::toMinor(449.52),
            PaymentAllocation::where('payment_id', $payment->id)->where('credit_schedule_item_id', $discount67->id)->value('allocated_amount_in_document_currency'),
        );
    }

    public function test_a_row_with_neither_cash_nor_credit_is_rejected(): void
    {
        $plainPo = PurchaseOrder::factory()->create(['supplier_company_id' => $this->supplier->id, 'currency_code' => 'USD']);
        $plain = $this->installment($plainPo, '30% — Balance', 100.00);

        $component = $this->createForm('10.00', [$plain]);
        $component
            ->set('data.allocations.'.$this->rowKey($component, 0).'.allocated_amount', '0')
            ->call('create')
            ->assertHasFormErrors();

        $this->assertSame(0, Payment::count());
    }

    public function test_edit_page_nests_the_saved_credit_under_its_installment(): void
    {
        $payment = Payment::create([
            'direction' => PaymentDirection::OUTBOUND,
            'company_id' => $this->supplier->id,
            'amount' => Money::toMinor(45789.81),
            'currency_code' => 'USD',
            'payment_date' => '2026-09-11',
            'status' => PaymentStatus::PENDING_APPROVAL,
        ]);
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'payment_schedule_item_id' => $this->installment71->id,
            'allocated_amount' => Money::toMinor(45789.81),
            'exchange_rate' => null,
            'allocated_amount_in_document_currency' => Money::toMinor(45789.81),
        ]);
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'payment_schedule_item_id' => $this->installment71->id,
            'credit_schedule_item_id' => $this->discount71->id,
            'allocated_amount' => 0,
            'exchange_rate' => null,
            'allocated_amount_in_document_currency' => Money::toMinor(2050.29),
        ]);

        $component = Livewire::test(EditAccountsPayable::class, ['record' => $payment->getRouteKey()]);
        $rows = array_values($component->get('data.allocations'));

        $this->assertCount(1, $rows);
        $credits = array_values($rows[0]['credits']);
        $this->assertCount(1, $credits);
        $this->assertSame($this->discount71->id, (int) $credits[0]['credit_schedule_item_id']);
        $this->assertEqualsWithDelta(2050.29, (float) $credits[0]['credit_amount'], 0.001);
        $this->assertNull($component->get('data.credit_applications'));
    }
}
