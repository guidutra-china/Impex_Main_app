<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\Settings\Models\Currency;
use App\Filament\Resources\Finance\AccountsReceivable\Pages\CreateAccountsReceivable;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * O formulário de pagamento é um só (HasPaymentFormSections); este teste
 * prova que o lado do cliente (Recebimentos) tem o mesmo comportamento:
 * desconto da PI entra sozinho na parcela da PI e o dinheiro sai líquido.
 */
class ReceivableFormNestedCreditsTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    private PaymentScheduleItem $installment;

    private PaymentScheduleItem $discount;

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

        $this->client = Company::create(['name' => 'Deep Fitness', 'status' => 'active']);
        $this->client->companyRoles()->create(['role' => 'client']);

        $pi = ProformaInvoice::factory()->create(['company_id' => $this->client->id, 'currency_code' => 'USD', 'status' => 'confirmed']);

        $this->installment = PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'label' => '30% — Balance',
            'percentage' => 30,
            'amount' => Money::toMinor(10000.00),
            'currency_code' => 'USD',
            'status' => PaymentScheduleStatus::DUE->value,
            'is_blocking' => false,
            'is_credit' => false,
            'sort_order' => 2,
        ]);

        $this->discount = PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'label' => 'Discount: goodwill 2%',
            'percentage' => 0,
            'amount' => Money::toMinor(200.00),
            'currency_code' => 'USD',
            'status' => PaymentScheduleStatus::PENDING->value,
            'is_blocking' => false,
            'is_credit' => true,
            'sort_order' => 9,
        ]);
    }

    public function test_choosing_the_pi_installment_applies_its_discount_and_nets_the_cash(): void
    {
        $component = Livewire::test(CreateAccountsReceivable::class)
            ->fillForm([
                'company_id' => $this->client->id,
                'currency_code' => 'USD',
                'amount' => '9800.00',
                'payment_date' => '2026-09-11',
                'allocations' => [['payment_schedule_item_id' => $this->installment->id]],
            ]);

        $row = array_values($component->get('data.allocations'))[0];
        $credits = array_values($row['credits']);

        $this->assertCount(1, $credits);
        $this->assertSame($this->discount->id, (int) $credits[0]['credit_schedule_item_id']);
        $this->assertSame('200.00', (string) $credits[0]['credit_amount']);
        $this->assertEqualsWithDelta(9800.00, (float) $row['allocated_amount'], 0.001);

        $component->call('create')->assertHasNoFormErrors();

        $payment = Payment::latest('id')->firstOrFail();
        $this->assertSame(Money::toMinor(9800.00), PaymentAllocation::where('payment_id', $payment->id)->whereNull('credit_schedule_item_id')->value('allocated_amount_in_document_currency'));
        $this->assertSame(Money::toMinor(200.00), PaymentAllocation::where('payment_id', $payment->id)->where('credit_schedule_item_id', $this->discount->id)->value('allocated_amount_in_document_currency'));
    }
}
