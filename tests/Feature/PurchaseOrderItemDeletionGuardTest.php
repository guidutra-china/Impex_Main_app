<?php

namespace Tests\Feature;

use App\Domain\CRM\Models\Company;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\PurchaseOrders\Models\PurchaseOrderItem;
use Database\Factories\ProformaInvoiceItemFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A PO line that already has shipment items must not be deletable — deleting it
 * would null shipment_items.purchase_order_item_id (FK set null) and break the
 * PO<->shipment link.
 */
class PurchaseOrderItemDeletionGuardTest extends TestCase
{
    use RefreshDatabase;

    private function poItem(ProformaInvoice $pi, PurchaseOrder $po): PurchaseOrderItem
    {
        $piItem = ProformaInvoiceItemFactory::new()->create(['proforma_invoice_id' => $pi->id]);

        return PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'proforma_invoice_item_id' => $piItem->id,
            'quantity' => 100,
            'unit_cost' => 1000,
            'sort_order' => 0,
        ]);
    }

    public function test_po_item_with_shipment_cannot_be_deleted(): void
    {
        $supplier = Company::factory()->create();
        $pi = ProformaInvoice::factory()->create();
        $po = PurchaseOrder::factory()->create(['supplier_company_id' => $supplier->id, 'proforma_invoice_id' => $pi->id]);
        $poItem = $this->poItem($pi, $po);

        $shipment = Shipment::factory()->create();
        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $poItem->proforma_invoice_item_id,
            'purchase_order_item_id' => $poItem->id,
            'quantity' => 50,
            'sort_order' => 0,
        ]);

        $result = $poItem->delete();

        $this->assertFalse($result, 'Deleting a shipped PO line should be blocked.');
        $this->assertDatabaseHas('purchase_order_items', ['id' => $poItem->id]);
    }

    public function test_po_item_without_shipment_can_be_deleted(): void
    {
        $supplier = Company::factory()->create();
        $pi = ProformaInvoice::factory()->create();
        $po = PurchaseOrder::factory()->create(['supplier_company_id' => $supplier->id, 'proforma_invoice_id' => $pi->id]);
        $poItem = $this->poItem($pi, $po);

        $poItem->delete();

        $this->assertDatabaseMissing('purchase_order_items', ['id' => $poItem->id]);
    }
}
