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
 * Recebimento em BRL alocado numa PI em USD: a taxa gravada é sempre
 * USD por BRL (doc = pmt × rate), mas o usuário pode digitar/ver a taxa
 * como USD → BRL (ex.: 5,00) sem mudar o que é persistido.
 */
class ReceivableExchangeRateDirectionTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    private PaymentScheduleItem $installment;

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
        Currency::create([
            'code' => 'BRL', 'name' => 'Real', 'name_plural' => 'Reais',
            'symbol' => 'R$', 'decimal_places' => 2, 'is_base' => false, 'is_active' => true,
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
    }

    public function test_inverted_rate_typed_as_usd_to_brl_is_stored_as_brl_to_usd(): void
    {
        $component = Livewire::test(CreateAccountsReceivable::class)
            ->fillForm([
                'company_id' => $this->client->id,
                'currency_code' => 'BRL',
                'amount' => '1000.00',
                'payment_date' => '2026-10-14',
                'allocations' => [['payment_schedule_item_id' => $this->installment->id]],
            ]);

        $key = array_key_first($component->get('data.allocations'));

        $component
            ->fillForm(["allocations.{$key}.exchange_rate_inverted" => true])
            ->fillForm(["allocations.{$key}.exchange_rate" => '5'])
            ->fillForm(["allocations.{$key}.allocated_amount" => '1000.00']);

        $row = $component->get("data.allocations.{$key}");

        // Campo mostra USD → BRL = 5; 1.000 BRL × 0,2 = 200 USD do documento.
        $this->assertEqualsWithDelta(5.0, (float) $row['exchange_rate'], 1e-8);
        $this->assertEqualsWithDelta(200.00, (float) $row['allocated_amount_in_document_currency'], 0.001);

        $component->call('create')->assertHasNoFormErrors();

        $payment = Payment::latest('id')->firstOrFail();
        $allocation = PaymentAllocation::where('payment_id', $payment->id)->firstOrFail();

        $this->assertEqualsWithDelta(0.2, (float) $allocation->exchange_rate, 1e-8);
        $this->assertSame(Money::toMinor(1000.00), (int) $allocation->allocated_amount);
        $this->assertSame(Money::toMinor(200.00), (int) $allocation->allocated_amount_in_document_currency);
    }

    public function test_invert_action_flips_the_displayed_rate_without_changing_the_amounts(): void
    {
        $component = Livewire::test(CreateAccountsReceivable::class)
            ->fillForm([
                'company_id' => $this->client->id,
                'currency_code' => 'BRL',
                'amount' => '1000.00',
                'payment_date' => '2026-10-14',
                'allocations' => [['payment_schedule_item_id' => $this->installment->id]],
            ]);

        $key = array_key_first($component->get('data.allocations'));

        $component
            ->fillForm(["allocations.{$key}.exchange_rate" => '0.2'])
            ->fillForm(["allocations.{$key}.allocated_amount" => '1000.00'])
            ->callFormComponentAction("allocations.{$key}.exchange_rate", 'invertExchangeRate');

        $row = $component->get("data.allocations.{$key}");

        $this->assertTrue((bool) $row['exchange_rate_inverted']);
        $this->assertEqualsWithDelta(5.0, (float) $row['exchange_rate'], 1e-8);
        $this->assertEqualsWithDelta(1000.00, (float) $row['allocated_amount'], 0.001);
        $this->assertEqualsWithDelta(200.00, (float) $row['allocated_amount_in_document_currency'], 0.001);

        // Inverter de novo volta ao original.
        $component->callFormComponentAction("allocations.{$key}.exchange_rate", 'invertExchangeRate');
        $row = $component->get("data.allocations.{$key}");

        $this->assertFalse((bool) $row['exchange_rate_inverted']);
        $this->assertEqualsWithDelta(0.2, (float) $row['exchange_rate'], 1e-8);
    }
}
