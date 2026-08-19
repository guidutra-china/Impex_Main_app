<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Actions\SyncPaymentBankFeeAction;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\Settings\Enums\ExchangeRateStatus;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use App\Filament\Resources\Finance\AccountsPayable\Pages\CreateAccountsPayable;
use App\Filament\Resources\Finance\AccountsPayable\Pages\EditAccountsPayable;
use App\Filament\Resources\Finance\AccountsPayable\Schemas\PayableForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class PaymentBankFeeTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    private Company $supplier;

    private ProformaInvoice $pi;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Company::create(['name' => 'Buyer Co', 'status' => 'active']);
        $this->client->companyRoles()->create(['role' => 'client']);
        $this->supplier = Company::create(['name' => 'Shenzhen Maker', 'status' => 'active']);
        $this->supplier->companyRoles()->create(['role' => 'supplier']);

        $this->pi = ProformaInvoice::factory()->create([
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
        ]);

        // Wire sent to the supplier; the bank fee rides on top of it.
        $this->payment = Payment::create([
            'direction' => PaymentDirection::OUTBOUND->value,
            'company_id' => $this->supplier->id,
            'amount' => 100_000_000, // USD 10,000.00
            'currency_code' => 'USD',
            'payment_date' => '2026-08-19',
            'reference' => 'SWIFT-001',
            'status' => PaymentStatus::PENDING_APPROVAL->value,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function fee(array $overrides = []): array
    {
        return array_merge([
            'amount' => 30.00,
            'currency_code' => 'USD',
            'billable_to' => BillableTo::CLIENT->value,
            'process' => ProformaInvoice::class.':'.$this->pi->id,
            'description' => null,
        ], $overrides);
    }

    private function sync(?array $fee): ?AdditionalCost
    {
        return app(SyncPaymentBankFeeAction::class)->execute($this->payment, $fee);
    }

    private function primaryItem(AdditionalCost $cost): ?PaymentScheduleItem
    {
        return PaymentScheduleItem::where('source_type', AdditionalCost::class)
            ->where('source_id', $cost->id)
            ->withoutSideTags()
            ->first();
    }

    private function makeSupplierPo(): PurchaseOrder
    {
        return PurchaseOrder::create([
            'proforma_invoice_id' => $this->pi->id,
            'supplier_company_id' => $this->supplier->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-08-01',
        ]);
    }

    public function test_bank_fee_enum_case_is_complete(): void
    {
        $type = AdditionalCostType::BANK_FEE;

        $this->assertSame('bank_fee', $type->value);
        $this->assertSame('Bank Fee', $type->getEnglishLabel());
        $this->assertNotNull($type->getIcon());
        $this->assertSame('Taxa Bancária', __('enums.additional_cost_type.bank_fee', [], 'pt_BR'));
        $this->assertSame('Bank Fee', __('enums.additional_cost_type.bank_fee', [], 'en'));
    }

    public function test_client_fee_creates_cost_and_receivable_on_the_process(): void
    {
        $cost = $this->sync($this->fee());

        $this->assertNotNull($cost);
        $this->assertSame(AdditionalCostType::BANK_FEE, $cost->cost_type);
        $this->assertSame(BillableTo::CLIENT, $cost->billable_to);
        $this->assertSame($this->payment->id, $cost->source_payment_id);
        $this->assertSame(ProformaInvoice::class, $cost->costable_type);
        $this->assertSame($this->pi->id, $cost->costable_id);
        $this->assertSame(300_000, $cost->amount);
        $this->assertSame(300_000, $cost->amount_in_document_currency);
        $this->assertStringContainsString('SWIFT-001', $cost->description);
        $this->assertNull($cost->supplier_company_id);

        $item = $this->primaryItem($cost);
        $this->assertNotNull($item);
        $this->assertFalse((bool) $item->is_credit);
        $this->assertSame(ProformaInvoice::class, $item->payable_type);
        $this->assertSame($this->pi->id, $item->payable_id);
        $this->assertSame(300_000, $item->amount);

        // Fee is charged on top of the wire: the payment amount is untouched.
        $this->assertSame(100_000_000, $this->payment->refresh()->amount);
    }

    public function test_client_fee_surfaces_in_the_client_receivables(): void
    {
        $cost = $this->sync($this->fee());

        $ids = PayableForm::getCompanyScheduleItems($this->client->id, PaymentDirection::INBOUND)
            ->pluck('id');

        $this->assertContains($this->primaryItem($cost)->id, $ids->all());
    }

    public function test_supplier_fee_becomes_a_credit_on_that_suppliers_po(): void
    {
        $po = $this->makeSupplierPo();

        $cost = $this->sync($this->fee(['billable_to' => BillableTo::SUPPLIER->value]));

        $this->assertSame($this->supplier->id, $cost->supplier_company_id);

        $item = $this->primaryItem($cost);
        $this->assertNotNull($item);
        $this->assertTrue((bool) $item->is_credit);
        $this->assertSame(PurchaseOrder::class, $item->payable_type);
        $this->assertSame($po->id, $item->payable_id);
        $this->assertSame(300_000, $item->amount);

        $credits = PayableForm::getCompanyCreditItems($this->supplier->id, PaymentDirection::OUTBOUND)
            ->pluck('id');
        $this->assertContains($item->id, $credits->all());
    }

    public function test_company_fee_records_the_cost_without_charging_anyone(): void
    {
        $cost = $this->sync($this->fee(['billable_to' => BillableTo::COMPANY->value]));

        $this->assertSame(BillableTo::COMPANY, $cost->billable_to);
        $this->assertNull($this->primaryItem($cost));
        $this->assertSame(300_000, $cost->amount);
    }

    public function test_switching_the_bearer_to_company_drops_the_receivable(): void
    {
        $cost = $this->sync($this->fee());
        $this->assertNotNull($this->primaryItem($cost));

        $cost = $this->sync($this->fee(['billable_to' => BillableTo::COMPANY->value]));

        $this->assertNull($this->primaryItem($cost));
    }

    public function test_editing_the_fee_updates_cost_and_item_without_duplicating(): void
    {
        $this->sync($this->fee());
        $cost = $this->sync($this->fee(['amount' => 45.50]));

        $this->assertSame(1, AdditionalCost::where('source_payment_id', $this->payment->id)->count());
        $this->assertSame(455_000, $cost->amount);

        $items = PaymentScheduleItem::where('source_type', AdditionalCost::class)
            ->where('source_id', $cost->id)
            ->get();

        $this->assertCount(1, $items);
        $this->assertSame(455_000, $items->first()->amount);
    }

    public function test_moving_the_fee_to_another_process_moves_its_item(): void
    {
        $cost = $this->sync($this->fee());
        $shipment = Shipment::factory()->create([
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
        ]);

        $cost = $this->sync($this->fee(['process' => Shipment::class.':'.$shipment->id]));

        $this->assertSame(Shipment::class, $cost->costable_type);
        $this->assertSame($shipment->id, $cost->costable_id);

        $items = PaymentScheduleItem::where('source_type', AdditionalCost::class)
            ->where('source_id', $cost->id)
            ->get();

        $this->assertCount(1, $items);
        $this->assertSame(Shipment::class, $items->first()->payable_type);
        $this->assertSame($shipment->id, $items->first()->payable_id);
    }

    public function test_clearing_the_fee_removes_cost_and_item(): void
    {
        $cost = $this->sync($this->fee());
        $itemId = $this->primaryItem($cost)->id;

        $this->assertNull($this->sync(null));

        $this->assertNull(AdditionalCost::find($cost->id));
        $this->assertNull(PaymentScheduleItem::find($itemId));
    }

    public function test_blank_amount_is_treated_as_no_fee(): void
    {
        $this->assertNull($this->sync($this->fee(['amount' => null])));
        $this->assertSame(0, AdditionalCost::where('source_payment_id', $this->payment->id)->count());
    }

    public function test_a_fee_without_a_process_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->sync($this->fee(['process' => null]));
    }

    public function test_a_settled_fee_cannot_be_changed(): void
    {
        $cost = $this->sync($this->fee());
        $item = $this->primaryItem($cost);

        PaymentAllocation::create([
            'payment_id' => $this->payment->id,
            'payment_schedule_item_id' => $item->id,
            'allocated_amount' => 300_000,
            'allocated_amount_in_document_currency' => 300_000,
        ]);

        try {
            $this->sync($this->fee(['amount' => 90.00]));
            $this->fail('Changing a settled bank fee should be blocked.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }

        $this->assertSame(300_000, $cost->refresh()->amount);
    }

    public function test_a_settled_fee_cannot_be_removed(): void
    {
        $cost = $this->sync($this->fee());
        $item = $this->primaryItem($cost);

        PaymentAllocation::create([
            'payment_id' => $this->payment->id,
            'payment_schedule_item_id' => $item->id,
            'allocated_amount' => 300_000,
            'allocated_amount_in_document_currency' => 300_000,
        ]);

        $this->expectException(ValidationException::class);

        try {
            $this->sync(null);
        } finally {
            $this->assertNotNull(AdditionalCost::find($cost->id));
        }
    }

    public function test_editing_an_unrelated_field_of_a_settled_fee_is_allowed(): void
    {
        $cost = $this->sync($this->fee());
        $item = $this->primaryItem($cost);

        PaymentAllocation::create([
            'payment_id' => $this->payment->id,
            'payment_schedule_item_id' => $item->id,
            'allocated_amount' => 300_000,
            'allocated_amount_in_document_currency' => 300_000,
        ]);

        $cost = $this->sync($this->fee(['description' => 'Tarifa TT Bank of China']));

        $this->assertSame('Tarifa TT Bank of China', $cost->description);
    }

    public function test_deleting_the_payment_removes_an_unsettled_fee(): void
    {
        $cost = $this->sync($this->fee());
        $itemId = $this->primaryItem($cost)->id;

        $this->payment->delete();

        $this->assertNull(AdditionalCost::find($cost->id));
        $this->assertNull(PaymentScheduleItem::find($itemId));
    }

    public function test_deleting_the_payment_keeps_a_settled_fee(): void
    {
        $cost = $this->sync($this->fee());
        $item = $this->primaryItem($cost);

        PaymentAllocation::create([
            'payment_id' => $this->payment->id,
            'payment_schedule_item_id' => $item->id,
            'allocated_amount' => 300_000,
            'allocated_amount_in_document_currency' => 300_000,
        ]);

        $this->payment->delete();

        $this->assertNotNull(AdditionalCost::find($cost->id));
    }

    public function test_a_fee_in_another_currency_is_converted_to_the_process_currency(): void
    {
        $usd = $this->usd();
        $cny = Currency::create([
            'code' => 'CNY', 'name' => 'Chinese Yuan', 'name_plural' => 'Chinese Yuan',
            'symbol' => '¥', 'decimal_places' => 2, 'is_base' => false, 'is_active' => true,
        ]);
        ExchangeRate::create([
            'base_currency_id' => $usd->id,
            'target_currency_id' => $cny->id,
            'rate' => 7.0,
            'inverse_rate' => 1 / 7.0,
            'date' => today()->subDay()->toDateString(),
            'status' => ExchangeRateStatus::APPROVED,
        ]);

        // CNY 210.00 charged by the bank on a USD process → USD 30.00.
        $cost = $this->sync($this->fee(['amount' => 210.00, 'currency_code' => 'CNY']));

        $this->assertSame('CNY', $cost->currency_code);
        $this->assertSame(2_100_000, $cost->amount);
        $this->assertSame(300_000, $cost->amount_in_document_currency);
        $this->assertSame(300_000, $this->primaryItem($cost)->amount);
    }

    private function usd(): Currency
    {
        return Currency::firstOrCreate(['code' => 'USD'], [
            'name' => 'US Dollar', 'name_plural' => 'US Dollars',
            'symbol' => '$', 'decimal_places' => 2, 'is_base' => true, 'is_active' => true,
        ]);
    }

    private function actingAsAllowedUser(): User
    {
        $user = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $user->id ? true : null);
        $this->actingAs($user);

        return $user;
    }

    public function test_the_create_page_records_the_fee_alongside_the_payment(): void
    {
        $this->actingAsAllowedUser();
        $this->usd();

        Livewire::test(CreateAccountsPayable::class)
            ->fillForm([
                'company_id' => $this->supplier->id,
                'currency_code' => 'USD',
                'amount' => 5000.00,
                'payment_date' => '2026-08-19',
                'reference' => 'SWIFT-002',
                'bank_fee_amount' => 30.00,
                'bank_fee_currency_code' => 'USD',
                'bank_fee_billable_to' => BillableTo::CLIENT->value,
                'bank_fee_process' => ProformaInvoice::class.':'.$this->pi->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $payment = Payment::where('reference', 'SWIFT-002')->firstOrFail();
        $fee = $payment->bankFeeCost;

        $this->assertNotNull($fee);
        $this->assertSame(300_000, $fee->amount);
        $this->assertSame(AdditionalCostType::BANK_FEE, $fee->cost_type);
        $this->assertSame($this->pi->id, $fee->costable_id);
        // The fee rides on top: only the wire amount is stored on the payment.
        $this->assertSame(50_000_000, $payment->amount);
        $this->assertNotNull($this->primaryItem($fee));
    }

    public function test_the_edit_page_round_trips_and_clears_the_fee(): void
    {
        $this->actingAsAllowedUser();
        $this->usd();
        $cost = $this->sync($this->fee());

        Livewire::test(EditAccountsPayable::class, ['record' => $this->payment->getRouteKey()])
            ->assertFormSet([
                'bank_fee_amount' => 30.0,
                'bank_fee_billable_to' => BillableTo::CLIENT->value,
                'bank_fee_process' => ProformaInvoice::class.':'.$this->pi->id,
            ])
            ->fillForm(['bank_fee_amount' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull(AdditionalCost::find($cost->id));
    }

    public function test_the_fee_process_select_suggests_processes_from_the_allocations(): void
    {
        $item = PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $this->pi->id,
            'label' => 'Deposit',
            'percentage' => 30,
            'amount' => 30_000_000,
            'currency_code' => 'USD',
            'status' => 'due',
        ]);

        $options = PayableForm::bankFeeProcessOptions([
            ['payment_schedule_item_id' => $item->id],
        ]);

        $this->assertArrayHasKey(ProformaInvoice::class.':'.$this->pi->id, $options);
        $this->assertStringContainsString('Buyer Co', $options[ProformaInvoice::class.':'.$this->pi->id]);
    }
}
