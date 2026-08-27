<?php

namespace Tests\Feature\Logistics;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
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
 * Caso PI-2026-00049: uma linha da PI dividida em duas POs. O resolvedor de PO
 * usado ao criar itens de embarque é `->first()`, então TODOS os embarques da
 * PI ficam grudados na PO mais antiga — a segunda PO nunca recebe embarque, as
 * duas aparecem com o dobro embarcado nos widgets, e o recálculo de cronograma
 * cria uma parcela fantasma na PO errada.
 */
class FixSplitPoShipmentLinksCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{piItem: ProformaInvoiceItem, poA: PurchaseOrder, poB: PurchaseOrder, poItemA: PurchaseOrderItem, poItemB: PurchaseOrderItem, ship1: Shipment, ship2: Shipment, shipItem1: ShipmentItem, shipItem2: ShipmentItem}
     */
    private function createSplitPoScenario(): array
    {
        $client = Company::factory()->create();
        $supplier = Company::factory()->create();

        $term = PaymentTerm::create(['name' => 'PO Term '.uniqid(), 'is_active' => true]);
        PaymentTermStage::create([
            'payment_term_id' => $term->id, 'sort_order' => 1,
            'percentage' => 30, 'days' => 0, 'calculation_base' => CalculationBase::ORDER_DATE,
        ]);
        PaymentTermStage::create([
            'payment_term_id' => $term->id, 'sort_order' => 2,
            'percentage' => 70, 'days' => 0, 'calculation_base' => CalculationBase::BEFORE_SHIPMENT,
        ]);

        $pi = ProformaInvoice::factory()->create([
            'company_id' => $client->id,
            'status' => 'confirmed',
            'payment_term_id' => null,
        ]);

        // Linha da PI com 2 unidades — 1 para cada PO.
        $piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description' => 'Chest Press',
            'quantity' => 2,
            'unit_price' => 150000,
            'sort_order' => 1,
        ]);

        $poA = PurchaseOrder::factory()->create([
            'proforma_invoice_id' => $pi->id,
            'supplier_company_id' => $supplier->id,
            'payment_term_id' => $term->id,
            'status' => 'sent',
            'currency_code' => 'USD',
        ]);
        $poItemA = PurchaseOrderItem::create([
            'purchase_order_id' => $poA->id,
            'proforma_invoice_item_id' => $piItem->id,
            'description' => 'Chest Press',
            'quantity' => 1,
            'unit_cost' => 100000,
            'sort_order' => 1,
        ]);

        $poB = PurchaseOrder::factory()->create([
            'proforma_invoice_id' => $pi->id,
            'supplier_company_id' => $supplier->id,
            'payment_term_id' => $term->id,
            'status' => 'sent',
            'currency_code' => 'USD',
        ]);
        $poItemB = PurchaseOrderItem::create([
            'purchase_order_id' => $poB->id,
            'proforma_invoice_item_id' => $piItem->id,
            'description' => 'Chest Press',
            'quantity' => 1,
            'unit_cost' => 100000,
            'sort_order' => 1,
        ]);

        // Embarque 1: metade da PI, corretamente vinculado à PO A.
        $ship1 = Shipment::factory()->create([
            'company_id' => $client->id,
            'status' => ShipmentStatus::IN_TRANSIT,
            'currency_code' => 'USD',
            'etd' => '2026-07-26',
        ]);
        $shipItem1 = ShipmentItem::create([
            'shipment_id' => $ship1->id,
            'proforma_invoice_item_id' => $piItem->id,
            'purchase_order_item_id' => $poItemA->id,
            'quantity' => 1,
            'sort_order' => 1,
        ]);

        // Embarque 2: a outra metade — deveria ser da PO B, mas o `->first()`
        // grudou na PO A.
        $ship2 = Shipment::factory()->create([
            'company_id' => $client->id,
            'status' => ShipmentStatus::IN_TRANSIT,
            'currency_code' => 'USD',
            'etd' => '2026-08-01',
        ]);
        $shipItem2 = ShipmentItem::create([
            'shipment_id' => $ship2->id,
            'proforma_invoice_item_id' => $piItem->id,
            'purchase_order_item_id' => $poItemA->id,
            'quantity' => 1,
            'sort_order' => 1,
        ]);

        return compact('piItem', 'poA', 'poB', 'poItemA', 'poItemB', 'ship1', 'ship2', 'shipItem1', 'shipItem2');
    }

    private function phantomScheduleItem(PurchaseOrder $po, Shipment $shipment): PaymentScheduleItem
    {
        return PaymentScheduleItem::create([
            'payable_type' => PurchaseOrder::class,
            'payable_id' => $po->id,
            'shipment_id' => $shipment->id,
            'label' => '70% — Before Shipment — ['.$shipment->reference.' / '.$po->reference.']',
            'percentage' => 70,
            'amount' => 70000,
            'currency_code' => 'USD',
            'due_condition' => CalculationBase::BEFORE_SHIPMENT->value,
            'status' => PaymentScheduleStatus::PENDING,
            'sort_order' => 3,
        ]);
    }

    public function test_dry_run_reports_the_relink_without_touching_the_database(): void
    {
        $s = $this->createSplitPoScenario();
        $phantom = $this->phantomScheduleItem($s['poA'], $s['ship2']);

        $this->artisan('shipments:fix-split-po-links')
            ->expectsOutputToContain('DRY-RUN')
            ->assertSuccessful();

        $this->assertSame($s['poItemA']->id, $s['shipItem2']->fresh()->purchase_order_item_id);
        $this->assertDatabaseHas('payment_schedule_items', ['id' => $phantom->id]);
    }

    public function test_apply_repoints_the_second_shipment_to_the_second_po(): void
    {
        $s = $this->createSplitPoScenario();

        $this->artisan('shipments:fix-split-po-links --apply')->assertSuccessful();

        // O embarque já correto não se mexe.
        $this->assertSame($s['poItemA']->id, $s['shipItem1']->fresh()->purchase_order_item_id);
        // O segundo passa para a PO B.
        $this->assertSame($s['poItemB']->id, $s['shipItem2']->fresh()->purchase_order_item_id);
    }

    public function test_apply_removes_the_phantom_schedule_item_from_the_wrong_po(): void
    {
        $s = $this->createSplitPoScenario();
        $phantom = $this->phantomScheduleItem($s['poA'], $s['ship2']);

        $this->artisan('shipments:fix-split-po-links --apply')->assertSuccessful();

        $this->assertDatabaseMissing('payment_schedule_items', ['id' => $phantom->id]);
    }

    public function test_paid_schedule_item_on_the_wrong_po_is_reported_not_deleted(): void
    {
        $s = $this->createSplitPoScenario();
        $phantom = $this->phantomScheduleItem($s['poA'], $s['ship2']);
        $phantom->update(['status' => PaymentScheduleStatus::PAID]);

        $this->artisan('shipments:fix-split-po-links --apply')
            ->expectsOutputToContain('revisão manual')
            ->assertSuccessful();

        $this->assertDatabaseHas('payment_schedule_items', ['id' => $phantom->id]);
    }

    public function test_shipments_of_an_unsplit_pi_item_are_left_alone(): void
    {
        $client = Company::factory()->create();
        $supplier = Company::factory()->create();

        $pi = ProformaInvoice::factory()->create(['company_id' => $client->id, 'status' => 'confirmed']);
        $piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description' => 'Widget',
            'quantity' => 10,
            'unit_price' => 1000,
            'sort_order' => 1,
        ]);
        $po = PurchaseOrder::factory()->create([
            'proforma_invoice_id' => $pi->id,
            'supplier_company_id' => $supplier->id,
            'status' => 'sent',
        ]);
        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'proforma_invoice_item_id' => $piItem->id,
            'description' => 'Widget',
            'quantity' => 10,
            'unit_cost' => 800,
            'sort_order' => 1,
        ]);
        $shipment = Shipment::factory()->create([
            'company_id' => $client->id,
            'status' => ShipmentStatus::IN_TRANSIT,
        ]);
        $shipItem = ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $piItem->id,
            'purchase_order_item_id' => $poItem->id,
            'quantity' => 4,
            'sort_order' => 1,
        ]);

        $this->artisan('shipments:fix-split-po-links --apply')->assertSuccessful();

        $this->assertSame($poItem->id, $shipItem->fresh()->purchase_order_item_id);
    }

    public function test_shipment_line_that_does_not_fit_a_single_po_line_is_reported_as_ambiguous(): void
    {
        $s = $this->createSplitPoScenario();

        // Um embarque único levando as 2 unidades não cabe em nenhuma das POs
        // (1 unidade cada) — exige split manual da linha.
        $s['shipItem1']->update(['quantity' => 2]);
        $s['shipItem2']->delete();

        $this->artisan('shipments:fix-split-po-links --apply')
            ->expectsOutputToContain('ambíguo')
            ->assertSuccessful();

        $this->assertSame($s['poItemA']->id, $s['shipItem1']->fresh()->purchase_order_item_id);
    }
}
