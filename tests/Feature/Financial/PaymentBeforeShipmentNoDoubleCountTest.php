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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reproduces the AP/AR double-counting bug fixed by
 * RecalculatePaymentScheduleForShipmentAction::promoteRemainingBaseItemsWithAllocations().
 *
 * Cenário: o cliente paga uma parcela dependente de embarque ANTES de o
 * embarque existir. A alocação fica no item canônico [remaining]
 * (shipment_id = null). Quando o embarque é criado e a agenda recalcula, o
 * item pago deve ser PROMOVIDO a ship-specific — e não duplicado em um novo
 * item pendente (que faria a mesma parcela aparecer como paga E pendente).
 */
class PaymentBeforeShipmentNoDoubleCountTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    private Inquiry $inquiry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Company::create(['name' => 'AP Test Client', 'status' => 'active']);
        $this->client->companyRoles()->create(['role' => 'client']);

        $this->inquiry = Inquiry::create([
            'reference' => 'INQ-AP-DC-001',
            'company_id' => $this->client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);
    }

    public function test_payment_before_shipment_promotes_remaining_instead_of_duplicating(): void
    {
        // ── Payment term: 30% on order date + 70% on shipment date ──────────
        $term = PaymentTerm::create(['name' => 'DC Term', 'is_active' => true]);
        $orderStage = PaymentTermStage::create([
            'payment_term_id' => $term->id, 'sort_order' => 1,
            'percentage' => 30, 'days' => 0, 'calculation_base' => CalculationBase::ORDER_DATE,
        ]);
        $shipmentStage = PaymentTermStage::create([
            'payment_term_id' => $term->id, 'sort_order' => 2,
            'percentage' => 70, 'days' => 0, 'calculation_base' => CalculationBase::SHIPMENT_DATE,
        ]);

        // ── PI with one item: 100 × $1000 (minor units) = 100,000 ───────────
        $pi = ProformaInvoice::create([
            'reference' => 'PI-AP-DC-001',
            'inquiry_id' => $this->inquiry->id,
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-03-01',
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

        // ── Base schedule as generated before any shipment exists ───────────
        // 30% order-date item (not shipment-dependent).
        PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'payment_term_stage_id' => $orderStage->id,
            'label' => '30% — Order Date — ['.$pi->reference.']',
            'percentage' => 30,
            'amount' => 30_000,
            'currency_code' => 'USD',
            'due_condition' => CalculationBase::ORDER_DATE,
            'status' => PaymentScheduleStatus::PENDING,
            'sort_order' => 1,
        ]);

        // 70% shipment-dependent canonical [remaining] item (shipment_id null).
        $remaining = PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'payment_term_stage_id' => $shipmentStage->id,
            'label' => '70% — Shipment Date — ['.$pi->reference.'] [remaining]',
            'percentage' => 70,
            'amount' => 70_000,
            'currency_code' => 'USD',
            'due_condition' => CalculationBase::SHIPMENT_DATE,
            'due_date' => null,
            'status' => PaymentScheduleStatus::PENDING,
            'sort_order' => 2,
        ]);

        // ── Client pays the 70% BEFORE the shipment exists ──────────────────
        $payment = Payment::create([
            'direction' => PaymentDirection::INBOUND,
            'company_id' => $this->client->id,
            'amount' => 70_000,
            'currency_code' => 'USD',
            'payment_date' => '2026-03-05',
            'reference' => 'PAY-AP-DC-001',
            'status' => PaymentStatus::APPROVED,
        ]);
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'payment_schedule_item_id' => $remaining->id,
            'allocated_amount' => 70_000,
            'exchange_rate' => 1.0,
            'allocated_amount_in_document_currency' => 70_000,
            'created_at' => '2026-03-05',
        ]);
        $remaining->recalculateStatus();
        $this->assertSame(PaymentScheduleStatus::PAID, $remaining->refresh()->status, 'precondition: [remaining] is paid');

        // ── Shipment created later, carrying the full PI quantity ───────────
        $shipment = Shipment::create([
            'reference' => 'SHP-AP-DC-001',
            'company_id' => $this->client->id,
            'issue_date' => '2026-03-20',
            'status' => ShipmentStatus::BOOKED,
            'currency_code' => 'USD',
            'created_by' => null,
        ]);
        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $piItem->id,
            'quantity' => 100,
            'total_weight' => 100,
            'total_volume' => 0,
            'sort_order' => 1,
        ]);

        // ── Recalculate the schedule for the new shipment ───────────────────
        app(RecalculatePaymentScheduleForShipmentAction::class)->execute($shipment);

        // ── The shipment-dependent stage must NOT be duplicated ─────────────
        $stageItems = PaymentScheduleItem::where('payable_type', ProformaInvoice::class)
            ->where('payable_id', $pi->id)
            ->where('payment_term_stage_id', $shipmentStage->id)
            ->get();

        $this->assertCount(1, $stageItems, 'shipment stage must resolve to a single PSI, not a paid+pending pair');

        $promoted = $stageItems->first();
        $this->assertSame($remaining->id, $promoted->id, 'the paid [remaining] was promoted (same row), not recreated');
        $this->assertSame($shipment->id, $promoted->shipment_id, 'item became ship-specific');
        $this->assertSame(PaymentScheduleStatus::PAID, $promoted->status, 'payment/allocation preserved through promotion');
        $this->assertSame(1, $promoted->allocations()->count(), 'allocation stayed attached to the promoted item');

        // Anti-double-count guard: no unpaid duplicate for this stage.
        $unpaidDuplicates = PaymentScheduleItem::where('payable_type', ProformaInvoice::class)
            ->where('payable_id', $pi->id)
            ->where('payment_term_stage_id', $shipmentStage->id)
            ->whereNotIn('status', [PaymentScheduleStatus::PAID->value, PaymentScheduleStatus::WAIVED->value])
            ->count();
        $this->assertSame(0, $unpaidDuplicates, 'no pending ship-specific clone of the already-paid installment');
    }
}
