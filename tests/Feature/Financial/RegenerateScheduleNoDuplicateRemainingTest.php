<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Actions\GeneratePaymentScheduleAction;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\Settings\Enums\CalculationBase;
use App\Domain\Settings\Models\PaymentTerm;
use App\Domain\Settings\Models\PaymentTermStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reproduz o bug da PI-2026-00020 / SH-2026-00027 (prod, 2026-07-08):
 * regenerar o cronograma da PI com um [remaining] já pago e um shipment
 * existente criava uma segunda parcela pendente do mesmo stage — a mesma
 * parcela aparecia paga E pendente. A regeneração deve PROMOVER o
 * [remaining] pago para ship-specific, como o recalc por shipment já faz.
 */
class RegenerateScheduleNoDuplicateRemainingTest extends TestCase
{
    use RefreshDatabase;

    public function test_pi_regeneration_promotes_paid_remaining_instead_of_duplicating(): void
    {
        $client = Company::create(['name' => 'Regen Client', 'status' => 'active']);
        $client->companyRoles()->create(['role' => 'client']);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-REGEN-001',
            'company_id' => $client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $term = PaymentTerm::create(['name' => 'Regen Term', 'is_active' => true]);
        $orderStage = PaymentTermStage::create([
            'payment_term_id' => $term->id, 'sort_order' => 1,
            'percentage' => 43, 'days' => 0, 'calculation_base' => CalculationBase::ORDER_DATE,
        ]);
        $shipmentStage = PaymentTermStage::create([
            'payment_term_id' => $term->id, 'sort_order' => 2,
            'percentage' => 57, 'days' => 0, 'calculation_base' => CalculationBase::BEFORE_SHIPMENT,
        ]);

        $pi = ProformaInvoice::create([
            'reference' => 'PI-REGEN-001',
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-06-01',
            'status' => 'confirmed',
            'payment_term_id' => $term->id,
        ]);
        $piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description' => 'Widget',
            'quantity' => 100,
            'unit_price' => 1000,
            'sort_order' => 1,
        ]);

        PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'payment_term_stage_id' => $orderStage->id,
            'label' => '43% — Order Date',
            'percentage' => 43,
            'amount' => 43_000,
            'currency_code' => 'USD',
            'due_condition' => CalculationBase::ORDER_DATE,
            'status' => PaymentScheduleStatus::PENDING,
            'sort_order' => 1,
        ]);
        $remaining = PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'payment_term_stage_id' => $shipmentStage->id,
            'label' => '57% — Before Shipment — ['.$pi->reference.'] [remaining]',
            'percentage' => 57,
            'amount' => 57_000,
            'currency_code' => 'USD',
            'due_condition' => CalculationBase::BEFORE_SHIPMENT,
            'status' => PaymentScheduleStatus::PENDING,
            'sort_order' => 2,
        ]);

        // Parcela de 57% paga ANTES de existir shipment.
        $payment = Payment::create([
            'direction' => PaymentDirection::INBOUND,
            'company_id' => $client->id,
            'amount' => 57_000,
            'currency_code' => 'USD',
            'payment_date' => '2026-06-10',
            'status' => PaymentStatus::APPROVED,
        ]);
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'payment_schedule_item_id' => $remaining->id,
            'allocated_amount' => 57_000,
            'exchange_rate' => 1.0,
            'allocated_amount_in_document_currency' => 57_000,
        ]);
        $remaining->recalculateStatus();
        $this->assertSame(PaymentScheduleStatus::PAID, $remaining->refresh()->status);

        $shipment = Shipment::create([
            'reference' => 'SH-REGEN-001',
            'company_id' => $client->id,
            'issue_date' => '2026-07-01',
            'status' => ShipmentStatus::IN_TRANSIT,
            'currency_code' => 'USD',
        ]);
        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $piItem->id,
            'quantity' => 100,
            'total_weight' => 100,
            'total_volume' => 0,
            'sort_order' => 1,
        ]);

        // Regeneração no nível da PI (o caminho que criava a duplicata).
        app(GeneratePaymentScheduleAction::class)->regenerate($pi);

        $stageItems = PaymentScheduleItem::where('payable_type', ProformaInvoice::class)
            ->where('payable_id', $pi->id)
            ->where('payment_term_stage_id', $shipmentStage->id)
            ->get();

        $this->assertCount(1, $stageItems, 'stage 57% deve ter UMA parcela, não paga+pendente');

        $promoted = $stageItems->first();
        $this->assertSame($remaining->id, $promoted->id, 'o [remaining] pago foi promovido, não recriado');
        $this->assertSame($shipment->id, $promoted->shipment_id);
        $this->assertSame(PaymentScheduleStatus::PAID, $promoted->status);
        $this->assertStringContainsString($shipment->reference, $promoted->label);
        $this->assertSame(1, $promoted->allocations()->count());
    }
}
