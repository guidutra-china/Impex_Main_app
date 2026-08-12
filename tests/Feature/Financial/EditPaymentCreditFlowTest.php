<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Actions\ApprovePaymentAction;
use App\Domain\Financial\Actions\ReconcileSettlementStateAction;
use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Filament\Resources\Finance\Concerns\HasPaymentFormSections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fluxo de EDIÇÃO de pagamento com créditos — os dois gaps que travavam a
 * correção de um pagamento com desconto aplicado:
 *  1. o mass-delete compensado dos Edit pages pulava créditos (PAID obsoleto);
 *  2. a validação/opções do crédito contavam a aplicação do PRÓPRIO
 *     pagamento, zerando o disponível e forçando remover a linha.
 */
class EditPaymentCreditFlowTest extends TestCase
{
    use RefreshDatabase;

    private ProformaInvoice $pi;

    private PaymentScheduleItem $target;

    private PaymentScheduleItem $credit;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        $client = Company::create(['name' => 'Buyer Co', 'status' => 'active']);
        $this->pi = ProformaInvoice::factory()->create(['company_id' => $client->id, 'currency_code' => 'USD']);

        // Custo de desconto → crédito (source AdditionalCost, gerido pelo reconcile).
        $cost = $this->pi->additionalCosts()->create([
            'cost_type' => AdditionalCostType::DISCOUNT->value,
            'description' => 'Desconto teste',
            'amount' => 2_437_200,
            'currency_code' => 'USD',
            'exchange_rate' => 1,
            'amount_in_document_currency' => 2_437_200,
            'billable_to' => BillableTo::CLIENT->value,
            'status' => AdditionalCostStatus::PENDING->value,
        ]);

        $this->target = PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $this->pi->id,
            'label' => '100% — Order Date',
            'percentage' => 100,
            'amount' => 81_239_600,
            'currency_code' => 'USD',
            'status' => PaymentScheduleStatus::DUE->value,
            'is_blocking' => false,
            'is_credit' => false,
            'sort_order' => 1,
        ]);

        $this->credit = PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $this->pi->id,
            'label' => 'Discount: Desconto teste',
            'percentage' => 0,
            'amount' => 2_437_200,
            'currency_code' => 'USD',
            'status' => PaymentScheduleStatus::PENDING->value,
            'is_blocking' => false,
            'is_credit' => true,
            'source_type' => AdditionalCost::class,
            'source_id' => $cost->id,
            'sort_order' => 2,
        ]);

        // Pagamento líquido + aplicação do crédito, aprovado.
        $this->payment = Payment::create([
            'direction' => PaymentDirection::INBOUND,
            'company_id' => $client->id,
            'amount' => 78_802_400,
            'currency_code' => 'USD',
            'payment_date' => '2026-08-12',
            'status' => PaymentStatus::PENDING_APPROVAL,
        ]);

        PaymentAllocation::create([
            'payment_id' => $this->payment->id,
            'payment_schedule_item_id' => $this->target->id,
            'allocated_amount' => 78_802_400,
            'exchange_rate' => 1,
            'allocated_amount_in_document_currency' => 78_802_400,
        ]);

        PaymentAllocation::create([
            'payment_id' => $this->payment->id,
            'payment_schedule_item_id' => $this->target->id,
            'credit_schedule_item_id' => $this->credit->id,
            'allocated_amount' => 0,
            'exchange_rate' => 1,
            'allocated_amount_in_document_currency' => 2_437_200,
        ]);

        app(ApprovePaymentAction::class)->approve($this->payment);
    }

    public function test_setup_settles_everything_exactly(): void
    {
        $this->assertSame(PaymentScheduleStatus::PAID, $this->target->refresh()->status);
        $this->assertSame(PaymentScheduleStatus::PAID, $this->credit->refresh()->status);
        $this->assertFalse($this->target->is_overpaid);
    }

    public function test_recalculate_credit_item_status_fixes_stale_paid_after_mass_delete(): void
    {
        // Simula o Edit: mass-delete via query builder (observer NÃO dispara).
        $this->payment->allocations()->delete();

        // Estado obsoleto: crédito segue PAID sem consumo real.
        $this->assertSame(PaymentScheduleStatus::PAID, $this->credit->refresh()->status);
        $this->assertSame(0, $this->credit->credit_consumed_amount);

        app(ReconcileSettlementStateAction::class)->recalculateCreditItemStatus($this->credit);

        $this->assertSame(PaymentScheduleStatus::PENDING, $this->credit->refresh()->status, 'Crédito volta a ficar disponível.');
    }

    public function test_max_applicable_credit_ignores_own_payments_applications_on_edit(): void
    {
        $helper = new class
        {
            use HasPaymentFormSections;
        };

        // Sem ignorar: o próprio consumo zera o disponível (o bug que forçava
        // remover a linha rehidratada no Edit).
        $this->assertSame(0.0, $helper::maxApplicableCreditMajor($this->credit->refresh()));

        // Ignorando o próprio pagamento: a capacidade volta inteira.
        $this->assertSame(243.72, $helper::maxApplicableCreditMajor($this->credit->refresh(), $this->payment->id));
    }
}
