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
 * Reproduz o bug da PO-2026-00005 / SH-2026-00025 (prod, 2026-07-08):
 * regenerar o cronograma a partir do SHIPMENT só reconstruía o lado PI —
 * as parcelas ship-specific das POs presentes no shipment nunca eram
 * criadas e o valor embarcado ficava preso nas linhas [remaining] da PO.
 * O regenerate do shipment deve cobrir os dois lados (AR e AP).
 */
class RegenerateForShipmentCoversPoTest extends TestCase
{
    use RefreshDatabase;

    public function test_shipment_regeneration_creates_po_ship_specific_items(): void
    {
        $client = Company::factory()->create();
        $supplier = Company::factory()->create();

        $term = PaymentTerm::create(['name' => 'PO Term', 'is_active' => true]);
        PaymentTermStage::create([
            'payment_term_id' => $term->id, 'sort_order' => 1,
            'percentage' => 10, 'days' => 0, 'calculation_base' => CalculationBase::ORDER_DATE,
        ]);
        $beforeShipmentStage = PaymentTermStage::create([
            'payment_term_id' => $term->id, 'sort_order' => 2,
            'percentage' => 30, 'days' => 0, 'calculation_base' => CalculationBase::BEFORE_SHIPMENT,
        ]);
        $deliveryStage = PaymentTermStage::create([
            'payment_term_id' => $term->id, 'sort_order' => 3,
            'percentage' => 60, 'days' => -3, 'calculation_base' => CalculationBase::DELIVERY_DATE,
        ]);

        // PI sem payment term — isola o comportamento do lado PO.
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
            'unit_cost' => 1000, // PO total = 1_000_000
            'sort_order' => 1,
        ]);

        // Estado inicial: schedule da PO sem shipments → base 10% + [remaining] 30%/60%.
        app(GeneratePaymentScheduleAction::class)->regenerate($po->fresh());

        $this->assertSame(
            2,
            PaymentScheduleItem::where('payable_type', PurchaseOrder::class)
                ->where('payable_id', $po->id)
                ->where('label', 'LIKE', '%[remaining]%')
                ->count(),
            'pré-condição: valor não embarcado está nos [remaining] da PO',
        );

        // Shipment embarca 400 das 1000 unidades (valor 400_000).
        $shipment = Shipment::factory()->create([
            'company_id' => $client->id,
            'status' => ShipmentStatus::BOOKED,
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

        // Regeneração a partir do shipment — o caminho que ignorava o lado PO.
        app(GeneratePaymentScheduleAction::class)->regenerateForShipment($shipment);

        $shipSpecific = PaymentScheduleItem::where('payable_type', PurchaseOrder::class)
            ->where('payable_id', $po->id)
            ->where('shipment_id', $shipment->id)
            ->get();

        $this->assertCount(2, $shipSpecific, 'PO deve ganhar parcelas ship-specific dos stages 30% e 60%');

        $beforeItem = $shipSpecific->firstWhere('payment_term_stage_id', $beforeShipmentStage->id);
        $deliveryItem = $shipSpecific->firstWhere('payment_term_stage_id', $deliveryStage->id);

        $this->assertNotNull($beforeItem);
        $this->assertNotNull($deliveryItem);
        $this->assertSame(120_000, (int) $beforeItem->amount, '30% do valor embarcado (400_000)');
        $this->assertSame(240_000, (int) $deliveryItem->amount, '60% do valor embarcado (400_000)');
        $this->assertStringContainsString($shipment->reference, $beforeItem->label);

        // [remaining] encolhe para o valor não embarcado (600_000).
        $remaining = PaymentScheduleItem::where('payable_type', PurchaseOrder::class)
            ->where('payable_id', $po->id)
            ->where('label', 'LIKE', '%[remaining]%')
            ->get();

        $this->assertCount(2, $remaining);
        $this->assertSame(180_000, (int) $remaining->firstWhere('payment_term_stage_id', $beforeShipmentStage->id)->amount);
        $this->assertSame(360_000, (int) $remaining->firstWhere('payment_term_stage_id', $deliveryStage->id)->amount);
    }
}
