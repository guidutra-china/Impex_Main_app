<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Financial\Support\AllocationGuards;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guard anti-dupla-contagem do crédito no form de pagamento — reproduz o
 * caso real: parcela de 8.123,96, desconto de 243,72; alocar o valor CHEIO
 * em dinheiro + o crédito gera Overpaid fantasma e deve ser bloqueado.
 */
class AllocationGuardsTest extends TestCase
{
    use RefreshDatabase;

    private PaymentScheduleItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        $client = Company::create(['name' => 'Buyer Co', 'status' => 'active']);
        $pi = ProformaInvoice::factory()->create(['company_id' => $client->id, 'currency_code' => 'USD']);

        $this->item = PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'label' => '100% — Order Date',
            'percentage' => 100,
            'amount' => 81_239_600, // 8.123,96
            'currency_code' => 'USD',
            'status' => PaymentScheduleStatus::DUE->value,
            'is_blocking' => false,
            'is_credit' => false,
            'sort_order' => 1,
        ]);
    }

    public function test_full_cash_plus_credit_on_same_item_is_blocked(): void
    {
        // A armadilha exata: prefill deixou 8.123,96 em dinheiro e o usuário
        // aplicou o crédito de 243,72 por cima.
        $errors = AllocationGuards::overpayErrors(
            [['payment_schedule_item_id' => $this->item->id, 'allocated_amount' => 8_123.96]],
            [['payment_schedule_item_id' => $this->item->id, 'credit_amount' => 243.72]],
            7_880.24,
        );

        $this->assertNotEmpty($errors);
        // Os DOIS problemas são apontados: dinheiro > pagamento e parcela estourada.
        $this->assertCount(2, $errors);
    }

    public function test_net_cash_plus_credit_passes(): void
    {
        $errors = AllocationGuards::overpayErrors(
            [['payment_schedule_item_id' => $this->item->id, 'allocated_amount' => 7_880.24]],
            [['payment_schedule_item_id' => $this->item->id, 'credit_amount' => 243.72]],
            7_880.24,
        );

        $this->assertSame([], $errors);
    }

    public function test_genuine_overpay_without_credits_is_allowed_per_item(): void
    {
        // Sem crédito no form, a checagem por parcela não se aplica —
        // overpay genuíno continua registrável (só o teto do pagamento vale).
        $errors = AllocationGuards::overpayErrors(
            [['payment_schedule_item_id' => $this->item->id, 'allocated_amount' => 8_500.00]],
            [],
            8_500.00,
        );

        $this->assertSame([], $errors);
    }

    public function test_edit_flow_ignores_the_payments_own_previous_allocations(): void
    {
        // Pagamento aprovado já alocado na parcela; ao EDITAR esse mesmo
        // pagamento, as alocações antigas serão recriadas — não podem
        // reduzir a capacidade.
        $payment = Payment::create([
            'direction' => PaymentDirection::INBOUND,
            'company_id' => $this->item->payable->company_id,
            'amount' => 7_880.24 * 10000,
            'currency_code' => 'USD',
            'payment_date' => '2026-08-12',
            'status' => PaymentStatus::APPROVED,
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'payment_schedule_item_id' => $this->item->id,
            'allocated_amount' => 78_802_400,
            'exchange_rate' => 1,
            'allocated_amount_in_document_currency' => 78_802_400,
        ]);

        // Sem ignorar: capacidade cai para 243,72 e o mesmo par falharia.
        $withoutIgnore = AllocationGuards::overpayErrors(
            [['payment_schedule_item_id' => $this->item->id, 'allocated_amount' => 7_880.24]],
            [['payment_schedule_item_id' => $this->item->id, 'credit_amount' => 243.72]],
            7_880.24,
        );
        $this->assertNotEmpty($withoutIgnore);

        // Ignorando o próprio pagamento (fluxo de edição): passa.
        $withIgnore = AllocationGuards::overpayErrors(
            [['payment_schedule_item_id' => $this->item->id, 'allocated_amount' => 7_880.24]],
            [['payment_schedule_item_id' => $this->item->id, 'credit_amount' => 243.72]],
            7_880.24,
            $payment->id,
        );
        $this->assertSame([], $withIgnore);
    }

    public function test_cross_currency_rows_use_document_currency_for_the_item_check(): void
    {
        // Linha em moeda do pagamento diferente: a checagem por parcela usa
        // o valor em moeda do documento.
        $errors = AllocationGuards::overpayErrors(
            [[
                'payment_schedule_item_id' => $this->item->id,
                'allocated_amount' => 56_000.00, // CNY, irrelevante p/ parcela
                'allocated_amount_in_document_currency' => 7_880.24,
            ]],
            [['payment_schedule_item_id' => $this->item->id, 'credit_amount' => 243.72]],
            56_000.00,
        );

        $this->assertSame([], $errors);
    }
}
