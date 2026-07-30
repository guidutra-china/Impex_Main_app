<?php

namespace Tests\Feature\ProformaInvoices;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Filament\Resources\ProformaInvoices\Pages\ListProformaInvoices;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class PaidColumnColorTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $user = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $user->id ? true : null);
        $this->actingAs($user);

        $this->company = Company::create(['name' => 'Client Co', 'status' => 'active']);
        $this->company->companyRoles()->create(['role' => 'client']);
    }

    /**
     * PI com produtos = 1.000 e custo adicional de cliente = 200 (grand total 1.200).
     */
    private function createProformaInvoice(): ProformaInvoice
    {
        $inquiry = Inquiry::create([
            'reference' => 'INQ-TEST-'.uniqid(),
            'company_id' => $this->company->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $pi = ProformaInvoice::create([
            'reference' => 'PI-TEST-'.uniqid(),
            'inquiry_id' => $inquiry->id,
            'company_id' => $this->company->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-07-01',
            'status' => 'confirmed',
        ]);

        $pi->items()->create([
            'description' => 'Widget',
            'quantity' => 10,
            'unit_price' => Money::toMinor(100),
            'unit' => 'pcs',
        ]);

        $pi->additionalCosts()->create([
            'cost_type' => AdditionalCostType::FREIGHT,
            'description' => 'Freight',
            'currency_code' => 'USD',
            'amount' => Money::toMinor(200),
            'amount_in_document_currency' => Money::toMinor(200),
            'billable_to' => BillableTo::CLIENT,
        ]);

        return $pi->fresh();
    }

    private function payAmount(ProformaInvoice $pi, int $amount): void
    {
        $scheduleItem = $pi->paymentScheduleItems()->create([
            'label' => 'Stage',
            'percentage' => 100,
            'amount' => $amount,
            'currency_code' => 'USD',
            'status' => 'pending',
            'sort_order' => 1,
        ]);

        $payment = Payment::create([
            'direction' => PaymentDirection::INBOUND,
            'company_id' => $this->company->id,
            'amount' => $amount,
            'currency_code' => 'USD',
            'payment_date' => '2026-07-10',
            'status' => PaymentStatus::APPROVED,
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'payment_schedule_item_id' => $scheduleItem->id,
            'allocated_amount' => $amount,
            'exchange_rate' => 1.0,
            'allocated_amount_in_document_currency' => $amount,
        ]);
    }

    private function paidColumnColorFor(ProformaInvoice $pi): string|array|null
    {
        $column = Livewire::test(ListProformaInvoices::class)
            ->instance()
            ->getTable()
            ->getColumn('schedule_paid_total');

        $pi = $pi->fresh();
        $column->record($pi);

        return $column->getColor($pi->schedule_paid_total);
    }

    public function test_paid_equal_to_products_total_is_info(): void
    {
        $pi = $this->createProformaInvoice();
        $this->payAmount($pi, Money::toMinor(1000));

        $this->assertSame('info', $this->paidColumnColorFor($pi));
    }

    public function test_paid_equal_to_grand_total_is_success(): void
    {
        $pi = $this->createProformaInvoice();
        $this->payAmount($pi, Money::toMinor(1200));

        $this->assertSame('success', $this->paidColumnColorFor($pi));
    }

    public function test_partial_payment_is_warning(): void
    {
        $pi = $this->createProformaInvoice();
        $this->payAmount($pi, Money::toMinor(500));

        $this->assertSame('warning', $this->paidColumnColorFor($pi));
    }

    public function test_no_payment_is_gray(): void
    {
        $pi = $this->createProformaInvoice();

        $this->assertSame('gray', $this->paidColumnColorFor($pi));
    }
}
