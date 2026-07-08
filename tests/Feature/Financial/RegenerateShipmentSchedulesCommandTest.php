<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Actions\GeneratePaymentScheduleAction;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\PurchaseOrders\Models\PurchaseOrderItem;
use App\Domain\Settings\Enums\CalculationBase;
use App\Domain\Settings\Models\PaymentTerm;
use App\Domain\Settings\Models\PaymentTermStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reparo em massa do caso SH-2026-00025 / PO-2026-00005: shipments antigos
 * cujas POs nunca ganharam parcelas ship-specific porque a regeneração pelo
 * shipment só cobria o lado PI. O comando roda regenerateForShipment em
 * todos os shipments ativos sem precisar abrir um a um no painel.
 */
class RegenerateShipmentSchedulesCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{shipment: Shipment, po: PurchaseOrder}
     */
    private function createStaleShipmentScenario(ShipmentStatus $status = ShipmentStatus::BOOKED): array
    {
        $client = Company::factory()->create();
        $supplier = Company::factory()->create();

        $term = PaymentTerm::create(['name' => 'PO Term '.uniqid(), 'is_active' => true]);
        PaymentTermStage::create([
            'payment_term_id' => $term->id, 'sort_order' => 1,
            'percentage' => 10, 'days' => 0, 'calculation_base' => CalculationBase::ORDER_DATE,
        ]);
        PaymentTermStage::create([
            'payment_term_id' => $term->id, 'sort_order' => 2,
            'percentage' => 30, 'days' => 0, 'calculation_base' => CalculationBase::BEFORE_SHIPMENT,
        ]);
        PaymentTermStage::create([
            'payment_term_id' => $term->id, 'sort_order' => 3,
            'percentage' => 60, 'days' => -3, 'calculation_base' => CalculationBase::DELIVERY_DATE,
        ]);

        $pi = ProformaInvoice::factory()->create([
            'company_id' => $client->id,
            'status' => 'confirmed',
            'payment_term_id' => null,
        ]);
        $piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description' => 'Widget',
            'quantity' => 1000,
            'unit_price' => 1200,
            'sort_order' => 1,
        ]);

        $po = PurchaseOrder::factory()->create([
            'proforma_invoice_id' => $pi->id,
            'supplier_company_id' => $supplier->id,
            'payment_term_id' => $term->id,
            'status' => 'confirmed',
            'currency_code' => 'USD',
        ]);
        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'proforma_invoice_item_id' => $piItem->id,
            'description' => 'Widget',
            'quantity' => 1000,
            'unit_cost' => 1000,
            'sort_order' => 1,
        ]);

        // Schedule da PO gerado ANTES do shipment existir → valor fica no [remaining].
        app(GeneratePaymentScheduleAction::class)->regenerate($po->fresh());

        $shipment = Shipment::factory()->create([
            'company_id' => $client->id,
            'status' => $status,
            'currency_code' => 'USD',
        ]);
        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $piItem->id,
            'purchase_order_item_id' => $poItem->id,
            'quantity' => 400,
            'total_weight' => 100,
            'total_volume' => 0,
            'sort_order' => 1,
        ]);

        return ['shipment' => $shipment, 'po' => $po];
    }

    private function poShipSpecificCount(PurchaseOrder $po, Shipment $shipment): int
    {
        return PaymentScheduleItem::where('payable_type', PurchaseOrder::class)
            ->where('payable_id', $po->id)
            ->where('shipment_id', $shipment->id)
            ->count();
    }

    public function test_command_backfills_po_ship_specific_items_for_all_shipments(): void
    {
        $a = $this->createStaleShipmentScenario();
        $b = $this->createStaleShipmentScenario(ShipmentStatus::IN_TRANSIT);

        $this->assertSame(0, $this->poShipSpecificCount($a['po'], $a['shipment']));
        $this->assertSame(0, $this->poShipSpecificCount($b['po'], $b['shipment']));

        $this->artisan('financial:regenerate-shipment-schedules')
            ->assertSuccessful();

        $this->assertSame(2, $this->poShipSpecificCount($a['po'], $a['shipment']));
        $this->assertSame(2, $this->poShipSpecificCount($b['po'], $b['shipment']));
    }

    public function test_command_skips_draft_and_cancelled_shipments(): void
    {
        $draft = $this->createStaleShipmentScenario(ShipmentStatus::DRAFT);
        $cancelled = $this->createStaleShipmentScenario(ShipmentStatus::CANCELLED);

        $this->artisan('financial:regenerate-shipment-schedules')
            ->assertSuccessful();

        $this->assertSame(0, $this->poShipSpecificCount($draft['po'], $draft['shipment']));
        $this->assertSame(0, $this->poShipSpecificCount($cancelled['po'], $cancelled['shipment']));
    }

    public function test_dry_run_does_not_persist_changes(): void
    {
        $a = $this->createStaleShipmentScenario();

        $this->artisan('financial:regenerate-shipment-schedules --dry-run')
            ->assertSuccessful();

        $this->assertSame(0, $this->poShipSpecificCount($a['po'], $a['shipment']));
    }

    public function test_shipment_option_limits_scope(): void
    {
        $a = $this->createStaleShipmentScenario();
        $b = $this->createStaleShipmentScenario();

        $this->artisan('financial:regenerate-shipment-schedules --shipment='.$a['shipment']->reference)
            ->assertSuccessful();

        $this->assertSame(2, $this->poShipSpecificCount($a['po'], $a['shipment']));
        $this->assertSame(0, $this->poShipSpecificCount($b['po'], $b['shipment']));
    }
}
