<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Actions\RecalculatePaymentScheduleForShipmentAction;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\Settings\Enums\CalculationBase;
use App\Domain\Settings\Models\PaymentTerm;
use App\Domain\Settings\Models\PaymentTermStage;
use App\Filament\Resources\Finance\AccountsReceivable\Schemas\ReceivableForm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PI-2026-00085 / SH-2026-00066: a parcela "30% — Before Shipment" estava
 * quitada (19.035,00) quando o valor embarcado subiu e ela passou a valer
 * 19.073,07. O recálculo pelo embarque aumentou o valor mas deixou o status
 * em PAID, e o formulário de recebimento esconde parcelas PAID — os 38,07
 * que faltavam ficaram impossíveis de lançar.
 */
class ResizedPaidInstallmentReopensTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    private ProformaInvoice $pi;

    private ProformaInvoiceItem $piItem;

    private PaymentTermStage $shipmentStage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Company::create(['name' => 'Resize Client', 'status' => 'active']);
        $this->client->companyRoles()->create(['role' => 'client']);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-RS-001',
            'company_id' => $this->client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $term = PaymentTerm::create(['name' => 'Resize Term', 'is_active' => true]);
        PaymentTermStage::create([
            'payment_term_id' => $term->id, 'sort_order' => 1,
            'percentage' => 30, 'days' => 0, 'calculation_base' => CalculationBase::ORDER_DATE,
        ]);
        $this->shipmentStage = PaymentTermStage::create([
            'payment_term_id' => $term->id, 'sort_order' => 2,
            'percentage' => 70, 'days' => 0, 'calculation_base' => CalculationBase::BEFORE_SHIPMENT,
        ]);

        // PI: 100 × 1.000 (unidades mínimas) = 100.000.
        $this->pi = ProformaInvoice::create([
            'reference' => 'PI-RS-001',
            'inquiry_id' => $inquiry->id,
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-03-01',
            'status' => 'confirmed',
            'payment_term_id' => $term->id,
        ]);
        $this->piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $this->pi->id,
            'description' => 'Widget',
            'quantity' => 100,
            'unit_price' => 1000,
            'sort_order' => 1,
        ]);
    }

    private function shipmentCarrying(int $quantity): ShipmentItem
    {
        $shipment = Shipment::create([
            'reference' => 'SHP-RS-001',
            'company_id' => $this->client->id,
            'issue_date' => '2026-03-20',
            'status' => ShipmentStatus::BOOKED,
            'currency_code' => 'USD',
            'created_by' => null,
        ]);

        return ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $this->piItem->id,
            'quantity' => $quantity,
            'total_weight' => 100,
            'total_volume' => 0,
            'sort_order' => 1,
        ]);
    }

    private function shipSpecificInstallment(Shipment $shipment): PaymentScheduleItem
    {
        return PaymentScheduleItem::where('payable_type', ProformaInvoice::class)
            ->where('payable_id', $this->pi->id)
            ->where('shipment_id', $shipment->id)
            ->where('payment_term_stage_id', $this->shipmentStage->id)
            ->sole();
    }

    private function pay(PaymentScheduleItem $item, int $amount): void
    {
        $payment = Payment::create([
            'direction' => PaymentDirection::INBOUND,
            'company_id' => $this->client->id,
            'amount' => $amount,
            'currency_code' => 'USD',
            'payment_date' => '2026-03-22',
            'status' => PaymentStatus::APPROVED,
        ]);
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'payment_schedule_item_id' => $item->id,
            'allocated_amount' => $amount,
            'exchange_rate' => 1.0,
            'allocated_amount_in_document_currency' => $amount,
        ]);
        $item->recalculateStatus();
    }

    /** @return array<int, int> ids das parcelas que o formulário de recebimento oferece */
    private function receivableFormItemIds(): array
    {
        return ReceivableForm::getCompanyScheduleItems($this->client->id, PaymentDirection::INBOUND)
            ->pluck('id')
            ->all();
    }

    public function test_paid_installment_reopens_when_the_shipment_value_grows(): void
    {
        // Embarque com 90 de 100 unidades: parcela de 70% vale 63.000.
        $shipmentItem = $this->shipmentCarrying(90);
        $shipment = $shipmentItem->shipment;
        app(RecalculatePaymentScheduleForShipmentAction::class)->execute($shipment);

        $installment = $this->shipSpecificInstallment($shipment);
        $this->assertSame(63_000, $installment->amount, 'precondition: 70% of the shipped value');

        $this->pay($installment, 63_000);
        $this->assertSame(PaymentScheduleStatus::PAID, $installment->refresh()->status, 'precondition: installment is paid');
        $this->assertNotContains($installment->id, $this->receivableFormItemIds(), 'precondition: paid installments are hidden');

        // O embarque passa a levar as 100 unidades: a parcela sobe para 70.000.
        $shipmentItem->update(['quantity' => 100]);
        app(RecalculatePaymentScheduleForShipmentAction::class)->execute($shipment->fresh());

        $installment->refresh();
        $this->assertSame(70_000, $installment->amount);
        $this->assertSame(7_000, $installment->remaining_amount);
        $this->assertSame(PaymentScheduleStatus::DUE, $installment->status, 'underpaid installment cannot stay PAID');
        $this->assertContains($installment->id, $this->receivableFormItemIds(), 'the open balance must be payable from the receivable form');
    }

    public function test_installment_becomes_paid_when_the_shipment_value_shrinks_to_what_was_paid(): void
    {
        $shipmentItem = $this->shipmentCarrying(100);
        $shipment = $shipmentItem->shipment;
        app(RecalculatePaymentScheduleForShipmentAction::class)->execute($shipment);

        $installment = $this->shipSpecificInstallment($shipment);
        $this->pay($installment, 63_000);
        $this->assertNotSame(PaymentScheduleStatus::PAID, $installment->refresh()->status, 'precondition: partially paid');

        $shipmentItem->update(['quantity' => 90]);
        app(RecalculatePaymentScheduleForShipmentAction::class)->execute($shipment->fresh());

        $installment->refresh();
        $this->assertSame(63_000, $installment->amount);
        $this->assertSame(PaymentScheduleStatus::PAID, $installment->status);
    }

    public function test_unpaid_installment_keeps_its_lifecycle_status_when_resized(): void
    {
        // Sem pagamento nenhum, mudar o valor não é motivo para mexer no status:
        // PENDING pertence ao ciclo de vida do documento.
        $installment = PaymentScheduleItem::factory()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $this->pi->id,
            'amount' => 63_000,
            'status' => PaymentScheduleStatus::PENDING,
        ]);

        $installment->update(['amount' => 0]);

        $this->assertSame(PaymentScheduleStatus::PENDING, $installment->refresh()->status);
    }
}
