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
 * Regressão do Overpaid da PI-2026-00049 (2026-07-15): a promoção de um
 * [remaining] parcialmente alocado NÃO pode acontecer quando o shipment já
 * tem a própria parcela ship-specific coberta — as alocações do [remaining]
 * pertencem ao saldo não embarcado, e a promoção clamparia o valor
 * (alocado > amount = Overpaid) além de duplicar o stage no shipment.
 */
class PromoteRemainingSkipsCoveredShipmentTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    private ProformaInvoice $pi;

    private ProformaInvoiceItem $piItem;

    private PaymentTermStage $shipmentStage;

    private Shipment $shipmentA;

    private PaymentScheduleItem $shipSpecificA;

    private PaymentScheduleItem $remaining;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Company::create(['name' => 'Covered Client', 'status' => 'active']);
        $this->client->companyRoles()->create(['role' => 'client']);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-COV-001',
            'company_id' => $this->client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $term = PaymentTerm::create(['name' => 'Covered Term', 'is_active' => true]);
        PaymentTermStage::create([
            'payment_term_id' => $term->id, 'sort_order' => 1,
            'percentage' => 30, 'days' => 0, 'calculation_base' => CalculationBase::ORDER_DATE,
        ]);
        $this->shipmentStage = PaymentTermStage::create([
            'payment_term_id' => $term->id, 'sort_order' => 2,
            'percentage' => 70, 'days' => 0, 'calculation_base' => CalculationBase::SHIPMENT_DATE,
        ]);

        // PI: 100 × 1000 = 100,000 total → 70% stage = 70,000
        $this->pi = ProformaInvoice::create([
            'reference' => 'PI-COV-001',
            'inquiry_id' => $inquiry->id,
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-06-01',
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

        // Shipment A embarca 20 pcs → valor 20,000 → 70% = 14,000
        $this->shipmentA = Shipment::create([
            'reference' => 'SHP-COV-A',
            'company_id' => $this->client->id,
            'issue_date' => '2026-06-20',
            'status' => ShipmentStatus::BOOKED,
            'currency_code' => 'USD',
        ]);
        ShipmentItem::create([
            'shipment_id' => $this->shipmentA->id,
            'proforma_invoice_item_id' => $this->piItem->id,
            'quantity' => 20,
            'total_weight' => 20,
            'total_volume' => 0,
            'sort_order' => 1,
        ]);

        // Ship-specific do shipment A, integralmente paga.
        $this->shipSpecificA = PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $this->pi->id,
            'shipment_id' => $this->shipmentA->id,
            'payment_term_stage_id' => $this->shipmentStage->id,
            'label' => '70% — Shipment Date — [SHP-COV-A / PI-COV-001]',
            'percentage' => 70,
            'amount' => 14_000,
            'currency_code' => 'USD',
            'due_condition' => CalculationBase::SHIPMENT_DATE,
            'status' => PaymentScheduleStatus::PENDING,
            'is_blocking' => true,
            'sort_order' => 2,
        ]);
        $this->allocate($this->shipSpecificA, 14_000, 'PAY-COV-A');
        $this->assertSame(PaymentScheduleStatus::PAID, $this->shipSpecificA->refresh()->status);

        // [remaining] com pagamento PARCIAL antecipado do saldo não embarcado.
        $this->remaining = PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $this->pi->id,
            'payment_term_stage_id' => $this->shipmentStage->id,
            'label' => '70% — Shipment Date — [PI-COV-001] [remaining]',
            'percentage' => 70,
            'amount' => 56_000,
            'currency_code' => 'USD',
            'due_condition' => CalculationBase::SHIPMENT_DATE,
            'status' => PaymentScheduleStatus::PENDING,
            'is_blocking' => false,
            'sort_order' => 3,
        ]);
        $this->allocate($this->remaining, 40_000, 'PAY-COV-REM');
    }

    private function allocate(PaymentScheduleItem $item, int $amount, string $reference): void
    {
        $payment = Payment::create([
            'direction' => PaymentDirection::INBOUND,
            'company_id' => $this->client->id,
            'amount' => $amount,
            'currency_code' => 'USD',
            'payment_date' => '2026-07-01',
            'reference' => $reference,
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

    public function test_regenerate_keeps_partially_paid_remaining_when_shipment_stage_is_already_covered(): void
    {
        // Shipment B embarca mais 6 pcs → valor 6,000 → 70% = 4,200
        $shipmentB = Shipment::create([
            'reference' => 'SHP-COV-B',
            'company_id' => $this->client->id,
            'issue_date' => '2026-07-10',
            'status' => ShipmentStatus::BOOKED,
            'currency_code' => 'USD',
        ]);
        ShipmentItem::create([
            'shipment_id' => $shipmentB->id,
            'proforma_invoice_item_id' => $this->piItem->id,
            'quantity' => 6,
            'total_weight' => 6,
            'total_volume' => 0,
            'sort_order' => 1,
        ]);

        app(GeneratePaymentScheduleAction::class)->regenerate($this->pi);

        // O [remaining] NÃO foi promovido: segue sem shipment, com a alocação
        // intacta e o valor recalculado para o saldo não embarcado
        // (100,000 − 26,000) × 70% = 51,800 — parcial, nunca Overpaid.
        $remaining = $this->remaining->refresh();
        $this->assertNull($remaining->shipment_id, '[remaining] must not be promoted onto a covered shipment');
        $this->assertStringContainsString('[remaining]', $remaining->label);
        $this->assertSame(51_800, (int) $remaining->amount);
        $this->assertSame(1, $remaining->allocations()->count());
        $this->assertNotSame(PaymentScheduleStatus::PAID, $remaining->status);

        // Shipment A continua com UMA única parcela do stage, paga e íntegra.
        $stageItemsA = PaymentScheduleItem::where('payable_type', ProformaInvoice::class)
            ->where('payable_id', $this->pi->id)
            ->where('payment_term_stage_id', $this->shipmentStage->id)
            ->where('shipment_id', $this->shipmentA->id)
            ->get();
        $this->assertCount(1, $stageItemsA, 'covered shipment must not gain a duplicate stage row');
        $this->assertSame($this->shipSpecificA->id, $stageItemsA->first()->id);
        $this->assertSame(14_000, (int) $stageItemsA->first()->amount);
        $this->assertSame(PaymentScheduleStatus::PAID, $stageItemsA->first()->status);

        // Shipment B ganhou a própria parcela pendente de 4,200.
        $itemB = PaymentScheduleItem::where('payable_type', ProformaInvoice::class)
            ->where('payable_id', $this->pi->id)
            ->where('payment_term_stage_id', $this->shipmentStage->id)
            ->where('shipment_id', $shipmentB->id)
            ->first();
        $this->assertNotNull($itemB);
        $this->assertSame(4_200, (int) $itemB->amount);

        // Nenhum item do stage ficou com alocação acima do valor (Overpaid).
        PaymentScheduleItem::where('payable_type', ProformaInvoice::class)
            ->where('payable_id', $this->pi->id)
            ->where('payment_term_stage_id', $this->shipmentStage->id)
            ->withSum('allocations as allocated_total', 'allocated_amount')
            ->get()
            ->each(fn ($item) => $this->assertLessThanOrEqual(
                (int) $item->amount,
                (int) ($item->allocated_total ?? 0),
                "item {$item->id} ({$item->label}) is over-allocated",
            ));
    }

    public function test_repair_command_demotes_overpromoted_remaining(): void
    {
        // Reproduz o estado quebrado pós-bug: o [remaining] foi promovido para
        // o shipment A (já coberto), clampando o valor com alocação acima dele,
        // e um [remaining] vazio novo foi criado pela regeneração.
        $this->remaining->update([
            'shipment_id' => $this->shipmentA->id,
            'label' => '70% — Shipment Date — [SHP-COV-A / PI-COV-001]',
            'amount' => 14_000,
            'status' => PaymentScheduleStatus::PAID,
        ]);
        $emptyRemaining = PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $this->pi->id,
            'payment_term_stage_id' => $this->shipmentStage->id,
            'label' => '70% — Shipment Date — [PI-COV-001] [remaining]',
            'percentage' => 70,
            'amount' => 51_800,
            'currency_code' => 'USD',
            'due_condition' => CalculationBase::SHIPMENT_DATE,
            'status' => PaymentScheduleStatus::PENDING,
            'is_blocking' => false,
            'sort_order' => 4,
        ]);

        $this->artisan('financial:demote-overpromoted-remaining')
            ->assertSuccessful();

        $demoted = $this->remaining->refresh();
        $this->assertNull($demoted->shipment_id, 'over-promoted item reverted to [remaining]');
        $this->assertStringContainsString('[remaining]', $demoted->label);
        $this->assertSame(51_800, (int) $demoted->amount);
        $this->assertFalse((bool) $demoted->is_blocking);
        $this->assertSame(1, $demoted->allocations()->count(), 'allocations preserved');
        $this->assertNotSame(PaymentScheduleStatus::PAID, $demoted->status);

        $this->assertDatabaseMissing('payment_schedule_items', ['id' => $emptyRemaining->id]);

        // O irmão legítimo (pago) ficou intacto.
        $sibling = $this->shipSpecificA->refresh();
        $this->assertSame(14_000, (int) $sibling->amount);
        $this->assertSame(PaymentScheduleStatus::PAID, $sibling->status);
    }
}
