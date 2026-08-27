<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\Settings\Enums\CalculationBase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Caso PI-2026-00049: os 70% estavam integralmente pagos, mas o dinheiro
 * pendurado no `[remaining]` e numa parcela de embarque que não existia mais,
 * enquanto os dois embarques reais apareciam em aberto pelo mesmo total. Este
 * comando põe a alocação na linha certa sem criar nem destruir dinheiro.
 */
class RebalanceStageAllocationsCommandTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    private ProformaInvoice $pi;

    private ProformaInvoiceItem $piItem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Company::factory()->create();
        $this->pi = ProformaInvoice::factory()->create([
            'company_id' => $this->client->id,
            'status' => 'confirmed',
            'currency_code' => 'USD',
            'reference' => 'PI-TEST-0049',
        ]);
        $this->piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $this->pi->id,
            'description' => 'Chest Press',
            'quantity' => 10,
            'unit_price' => 100000,
            'sort_order' => 1,
        ]);
    }

    private function liveShipment(string $reference, string $etd): Shipment
    {
        $shipment = Shipment::factory()->create([
            'company_id' => $this->client->id,
            'reference' => $reference,
            'status' => ShipmentStatus::IN_TRANSIT,
            'currency_code' => 'USD',
            'etd' => $etd,
        ]);

        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $this->piItem->id,
            'quantity' => 1,
            'sort_order' => 1,
        ]);

        return $shipment;
    }

    private function scheduleItem(?Shipment $shipment, int $amountMinor, string $label, PaymentScheduleStatus $status = PaymentScheduleStatus::PENDING): PaymentScheduleItem
    {
        return PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $this->pi->id,
            'shipment_id' => $shipment?->id,
            'label' => $label,
            'percentage' => 70,
            'amount' => $amountMinor,
            'currency_code' => 'USD',
            'due_condition' => CalculationBase::BEFORE_SHIPMENT->value,
            'status' => $status,
            'sort_order' => 2,
        ]);
    }

    private function allocate(PaymentScheduleItem $psi, int $amountMinor, string $date): PaymentAllocation
    {
        $payment = Payment::create([
            'direction' => PaymentDirection::INBOUND,
            'company_id' => $this->client->id,
            'amount' => $amountMinor,
            'currency_code' => 'USD',
            'payment_date' => $date,
            'status' => PaymentStatus::APPROVED,
        ]);

        return PaymentAllocation::create([
            'payment_id' => $payment->id,
            'payment_schedule_item_id' => $psi->id,
            'allocated_amount' => $amountMinor,
            'exchange_rate' => 1.0,
            'allocated_amount_in_document_currency' => $amountMinor,
        ]);
    }

    private function allocatedOn(PaymentScheduleItem $psi): int
    {
        return (int) $psi->allocations()->sum('allocated_amount_in_document_currency');
    }

    /**
     * Espelha a PI-49: dois embarques reais em aberto, o mesmo total pago
     * dividido entre o `[remaining]` e a parcela de um embarque retirado.
     *
     * @return array{remaining: PaymentScheduleItem, dead: PaymentScheduleItem, a: PaymentScheduleItem, b: PaymentScheduleItem}
     */
    private function pi49Scenario(): array
    {
        $shipA = $this->liveShipment('SH-A', '2026-07-26');
        $shipB = $this->liveShipment('SH-B', '2026-08-01');

        $a = $this->scheduleItem($shipA, 700000, '70% — Before Shipment — [SH-A / '.$this->pi->reference.']');
        $b = $this->scheduleItem($shipB, 300000, '70% — Before Shipment — [SH-B / '.$this->pi->reference.']');

        $remaining = $this->scheduleItem(null, 900000, '70% — Before Shipment — ['.$this->pi->reference.'] [remaining]', PaymentScheduleStatus::PAID);
        $this->allocate($remaining, 500000, '2026-07-10');
        $this->allocate($remaining, 400000, '2026-07-14');

        $deadShipment = Shipment::factory()->create([
            'company_id' => $this->client->id,
            'reference' => 'SH-DEAD',
            'status' => ShipmentStatus::DRAFT,
            'currency_code' => 'USD',
        ]);
        $dead = $this->scheduleItem($deadShipment, 100000, '70% — Before Shipment — [SH-DEAD / '.$this->pi->reference.']', PaymentScheduleStatus::PAID);
        $this->allocate($dead, 100000, '2026-07-16');

        return compact('remaining', 'dead', 'a', 'b');
    }

    public function test_dry_run_does_not_move_anything(): void
    {
        $s = $this->pi49Scenario();

        $this->artisan('financial:rebalance-stage-allocations PI-TEST-0049')
            ->expectsOutputToContain('DRY-RUN')
            ->assertSuccessful();

        $this->assertSame(900000, $this->allocatedOn($s['remaining']));
        $this->assertSame(0, $this->allocatedOn($s['a']));
    }

    public function test_allocations_land_on_the_real_shipments_in_payment_date_order(): void
    {
        $s = $this->pi49Scenario();

        $this->artisan('financial:rebalance-stage-allocations PI-TEST-0049 --apply')->assertSuccessful();

        $this->assertSame(700000, $this->allocatedOn($s['a']));
        $this->assertSame(300000, $this->allocatedOn($s['b']));
    }

    public function test_an_allocation_straddling_the_boundary_is_split(): void
    {
        $s = $this->pi49Scenario();

        $this->artisan('financial:rebalance-stage-allocations PI-TEST-0049 --apply')->assertSuccessful();

        // 500.000 (10/07) vai inteira para SH-A; a de 400.000 (14/07) parte em
        // 200.000 para fechar SH-A e 200.000 para SH-B; a de 100.000 (16/07)
        // vai inteira para SH-B. Três alocações viram quatro — uma partida.
        $this->assertSame(4, PaymentAllocation::whereIn('payment_schedule_item_id', [$s['a']->id, $s['b']->id])->count());
        $this->assertSame(2, PaymentAllocation::where('payment_schedule_item_id', $s['a']->id)->count());
        $this->assertSame(2, PaymentAllocation::where('payment_schedule_item_id', $s['b']->id)->count());
    }

    public function test_no_money_is_created_or_destroyed(): void
    {
        $before = (int) PaymentAllocation::sum('allocated_amount_in_document_currency');
        $this->pi49Scenario();
        $seeded = (int) PaymentAllocation::sum('allocated_amount_in_document_currency');

        $this->artisan('financial:rebalance-stage-allocations PI-TEST-0049 --apply')->assertSuccessful();

        $this->assertSame($seeded, (int) PaymentAllocation::sum('allocated_amount_in_document_currency'));
        $this->assertSame($before + 1000000, $seeded);
    }

    public function test_emptied_source_items_are_removed(): void
    {
        $s = $this->pi49Scenario();

        $this->artisan('financial:rebalance-stage-allocations PI-TEST-0049 --apply')->assertSuccessful();

        $this->assertDatabaseMissing('payment_schedule_items', ['id' => $s['remaining']->id]);
        $this->assertDatabaseMissing('payment_schedule_items', ['id' => $s['dead']->id]);
    }

    public function test_target_status_becomes_paid(): void
    {
        $s = $this->pi49Scenario();

        $this->artisan('financial:rebalance-stage-allocations PI-TEST-0049 --apply')->assertSuccessful();

        $this->assertSame(PaymentScheduleStatus::PAID, $s['a']->fresh()->status);
        $this->assertSame(PaymentScheduleStatus::PAID, $s['b']->fresh()->status);
    }

    public function test_without_any_live_shipment_the_item_is_detached_keeping_its_money(): void
    {
        // Caso PO-2026-00057: nada embarcado ainda, então a parcela volta a ser
        // do saldo não embarcado em vez de apontar para um embarque retirado.
        $deadShipment = Shipment::factory()->create([
            'company_id' => $this->client->id,
            'reference' => 'SH-DEAD',
            'status' => ShipmentStatus::DRAFT,
            'currency_code' => 'USD',
        ]);
        $item = $this->scheduleItem($deadShipment, 580000, '70% — Before Shipment — [SH-DEAD / '.$this->pi->reference.']');
        $this->allocate($item, 3800, '2026-07-16');

        $this->artisan('financial:rebalance-stage-allocations PI-TEST-0049 --apply')->assertSuccessful();

        $item->refresh();
        $this->assertNull($item->shipment_id);
        $this->assertStringNotContainsString('SH-DEAD', $item->label);
        $this->assertSame(3800, $this->allocatedOn($item));
    }

    public function test_a_document_already_in_order_reports_nothing_to_do(): void
    {
        $shipA = $this->liveShipment('SH-A', '2026-07-26');
        $a = $this->scheduleItem($shipA, 700000, '70% — Before Shipment — [SH-A / '.$this->pi->reference.']');
        $this->allocate($a, 700000, '2026-07-10');

        $this->artisan('financial:rebalance-stage-allocations PI-TEST-0049 --apply')
            ->expectsOutputToContain('Nada a redistribuir')
            ->assertSuccessful();

        $this->assertSame(700000, $this->allocatedOn($a));
    }

    public function test_unknown_document_fails(): void
    {
        $this->artisan('financial:rebalance-stage-allocations PI-NAO-EXISTE')
            ->assertFailed();
    }
}
