<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\DebitNoteStatus;
use App\Domain\Financial\Enums\PartyType;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\DebitNote;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Financial\Support\DebitNoteBundle;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Settings\Models\Currency;
use App\Filament\Resources\Finance\AccountsReceivable\Pages\CreateAccountsReceivable;
use App\Filament\Resources\Finance\AccountsReceivable\Pages\EditAccountsReceivable;
use App\Filament\Resources\Finance\AccountsReceivable\Schemas\ReceivableForm;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * DN-2026-0016 (viagem, PERFIL SUL): cada linha da DN tem a sua parcela e o
 * formulário de recebimento listava linha a linha. No formulário a DN passa
 * a ser UMA opção com o total; ao salvar, o valor é distribuído pelas linhas
 * — o modelo de dados (uma parcela por linha) não muda.
 */
class DebitNoteBundleTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    private DebitNote $dn;

    /** @var list<PaymentScheduleItem> */
    private array $lines = [];

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

        $this->client = Company::create(['name' => 'Perfil Sul', 'status' => 'active']);
        $this->client->companyRoles()->create(['role' => 'client']);

        $this->dn = DebitNote::create([
            'company_id' => $this->client->id,
            'party_type' => PartyType::CLIENT,
            'currency_code' => 'USD',
            'status' => DebitNoteStatus::ISSUED,
        ]);

        foreach ([['Hotel', 300.00], ['Flight', 500.00], ['Meals', 200.00]] as $i => [$label, $amount]) {
            $this->lines[] = PaymentScheduleItem::create([
                'payable_type' => DebitNote::class,
                'payable_id' => $this->dn->id,
                'label' => $label,
                'percentage' => 0,
                'amount' => Money::toMinor($amount),
                'currency_code' => 'USD',
                'status' => PaymentScheduleStatus::DUE->value,
                'is_blocking' => false,
                'is_credit' => false,
                'sort_order' => $i + 1,
            ]);
        }
    }

    public function test_the_picker_offers_the_debit_note_once_with_its_total(): void
    {
        $options = ReceivableForm::allocationItemOptions($this->client->id, PaymentDirection::INBOUND);

        $this->assertCount(1, $options);
        $this->assertSame($this->lines[0]->id, array_key_first($options));
        $label = (string) reset($options);
        $this->assertStringContainsString($this->dn->reference, $label);
        $this->assertStringContainsString('1,000.00', $label);
        $this->assertStringNotContainsString('Hotel', $label);
    }

    public function test_outstanding_items_table_shows_the_debit_note_once(): void
    {
        $html = ReceivableForm::buildOutstandingTable(
            ReceivableForm::getCompanyScheduleItems($this->client->id, PaymentDirection::INBOUND),
        );

        $this->assertSame(1, substr_count($html, $this->dn->reference));
        $this->assertStringContainsString('Debit Note total (3 items)', $html);
        $this->assertStringContainsString('1,000.00', $html);
        $this->assertStringNotContainsString('Hotel', $html);
    }

    public function test_full_payment_is_spread_over_every_line(): void
    {
        $rows = DebitNoteBundle::expandRows([[
            'payment_schedule_item_id' => $this->lines[0]->id,
            'allocated_amount' => '1000.00',
            'allocated_amount_in_document_currency' => '1000.00',
        ]]);

        $this->assertSame(
            [$this->lines[0]->id => 300.0, $this->lines[1]->id => 500.0, $this->lines[2]->id => 200.0],
            collect($rows)->mapWithKeys(fn ($r) => [$r['payment_schedule_item_id'] => (float) $r['allocated_amount']])->all(),
        );
    }

    public function test_partial_payment_fills_lines_in_order_and_skips_what_others_already_paid(): void
    {
        // Outro pagamento aprovado já quitou a 1ª linha (300).
        $other = Payment::create([
            'direction' => PaymentDirection::INBOUND, 'company_id' => $this->client->id, 'amount' => Money::toMinor(300),
            'currency_code' => 'USD', 'payment_date' => '2026-10-01', 'status' => PaymentStatus::APPROVED,
        ]);
        PaymentAllocation::create([
            'payment_id' => $other->id, 'payment_schedule_item_id' => $this->lines[0]->id, 'allocated_amount' => Money::toMinor(300),
            'exchange_rate' => null, 'allocated_amount_in_document_currency' => Money::toMinor(300),
        ]);

        $rows = DebitNoteBundle::expandRows([[
            'payment_schedule_item_id' => $this->lines[1]->id,
            'allocated_amount' => '600.00',
            'allocated_amount_in_document_currency' => '600.00',
        ]]);

        $this->assertSame(
            [$this->lines[1]->id => 500.0, $this->lines[2]->id => 100.0],
            collect($rows)->mapWithKeys(fn ($r) => [$r['payment_schedule_item_id'] => (float) $r['allocated_amount']])->all(),
        );
        $this->assertSame(Money::toMinor(700), DebitNoteBundle::remainingMinor($this->lines[1]->fresh()));
    }

    public function test_rows_of_other_documents_pass_through_untouched(): void
    {
        $single = DebitNote::create([
            'company_id' => $this->client->id, 'party_type' => PartyType::CLIENT, 'currency_code' => 'USD', 'status' => DebitNoteStatus::ISSUED,
        ]);
        $only = PaymentScheduleItem::create([
            'payable_type' => DebitNote::class, 'payable_id' => $single->id, 'label' => 'Single', 'percentage' => 0,
            'amount' => Money::toMinor(50), 'currency_code' => 'USD', 'status' => PaymentScheduleStatus::DUE->value,
            'is_blocking' => false, 'is_credit' => false, 'sort_order' => 1,
        ]);
        $row = ['payment_schedule_item_id' => $only->id, 'allocated_amount' => '50.00', 'allocated_amount_in_document_currency' => '50.00'];

        $this->assertSame([$row], DebitNoteBundle::expandRows([$row]));
    }

    public function test_receiving_the_debit_note_from_the_form_creates_one_allocation_per_line(): void
    {
        $component = Livewire::test(CreateAccountsReceivable::class)
            ->fillForm([
                'company_id' => $this->client->id,
                'currency_code' => 'USD',
                'amount' => '1000.00',
                'payment_date' => '2026-10-05',
                'allocations' => [['payment_schedule_item_id' => $this->lines[0]->id]],
            ]);

        // Pré-carga com o TOTAL da DN, não com a 1ª linha.
        $this->assertEqualsWithDelta(1000.00, (float) array_values($component->get('data.allocations'))[0]['allocated_amount'], 0.001);

        $component->call('create')->assertHasNoFormErrors();

        $payment = Payment::latest('id')->firstOrFail();
        $this->assertSame(
            [$this->lines[0]->id => Money::toMinor(300), $this->lines[1]->id => Money::toMinor(500), $this->lines[2]->id => Money::toMinor(200)],
            PaymentAllocation::where('payment_id', $payment->id)->orderBy('payment_schedule_item_id')->pluck('allocated_amount_in_document_currency', 'payment_schedule_item_id')->all(),
        );
    }

    public function test_edit_page_shows_the_saved_lines_back_as_one_row(): void
    {
        $payment = Payment::create([
            'direction' => PaymentDirection::INBOUND, 'company_id' => $this->client->id, 'amount' => Money::toMinor(1000),
            'currency_code' => 'USD', 'payment_date' => '2026-10-05', 'status' => PaymentStatus::PENDING_APPROVAL,
        ]);
        foreach ($this->lines as $line) {
            PaymentAllocation::create([
                'payment_id' => $payment->id, 'payment_schedule_item_id' => $line->id, 'allocated_amount' => $line->amount,
                'exchange_rate' => null, 'allocated_amount_in_document_currency' => $line->amount,
            ]);
        }

        $rows = array_values(Livewire::test(EditAccountsReceivable::class, ['record' => $payment->getRouteKey()])->get('data.allocations'));

        $this->assertCount(1, $rows);
        $this->assertSame($this->lines[0]->id, (int) $rows[0]['payment_schedule_item_id']);
        $this->assertEqualsWithDelta(1000.00, (float) $rows[0]['allocated_amount'], 0.001);
    }
}
