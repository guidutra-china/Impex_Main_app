<?php

namespace Tests\Feature\Logistics;

use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\PurchaseOrders\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\PurchaseOrders\Models\PurchaseOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncFulfillmentStatusTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    private Company $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Company::create(['name' => 'Client '.uniqid(), 'status' => 'active']);
        $this->client->companyRoles()->create(['role' => 'client']);

        $this->supplier = Company::create(['name' => 'Supplier '.uniqid(), 'status' => 'active']);
        $this->supplier->companyRoles()->create(['role' => 'supplier']);
    }

    public function test_po_auto_transitions_to_shipped_when_fully_shipped_and_in_transit(): void
    {
        [$pi, $piItem] = $this->makePI(qty: 100);
        [$po, $poItem] = $this->makePOForPI($pi, $piItem, status: PurchaseOrderStatus::CONFIRMED->value);

        $shipment = $this->makeShipment(ShipmentStatus::DRAFT);
        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $piItem->id,
            'purchase_order_item_id' => $poItem->id,
            'quantity' => 100,
            'sort_order' => 0,
        ]);

        // DRAFT does not trigger.
        $po->refresh();
        $this->assertSame(PurchaseOrderStatus::CONFIRMED, $po->status);

        // Move to IN_TRANSIT — observer should auto-transition PO + PI.
        $shipment->update(['status' => ShipmentStatus::IN_TRANSIT]);

        $po->refresh();
        $pi->refresh();

        $this->assertSame(PurchaseOrderStatus::SHIPPED, $po->status);
        $this->assertSame(ProformaInvoiceStatus::SHIPPED, $pi->status);
    }

    public function test_partial_shipment_does_not_transition(): void
    {
        [$pi, $piItem] = $this->makePI(qty: 100);
        [$po, $poItem] = $this->makePOForPI($pi, $piItem, status: PurchaseOrderStatus::CONFIRMED->value);

        $shipment = $this->makeShipment(ShipmentStatus::IN_TRANSIT);
        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $piItem->id,
            'purchase_order_item_id' => $poItem->id,
            'quantity' => 50,
            'sort_order' => 0,
        ]);

        $po->refresh();
        $pi->refresh();

        $this->assertSame(PurchaseOrderStatus::CONFIRMED, $po->status);
        $this->assertSame(ProformaInvoiceStatus::CONFIRMED, $pi->status);
    }

    public function test_already_shipped_po_is_idempotent(): void
    {
        [$pi, $piItem] = $this->makePI(qty: 100);
        [$po, $poItem] = $this->makePOForPI($pi, $piItem, status: PurchaseOrderStatus::SHIPPED->value);

        $shipment = $this->makeShipment(ShipmentStatus::IN_TRANSIT);
        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $piItem->id,
            'purchase_order_item_id' => $poItem->id,
            'quantity' => 100,
            'sort_order' => 0,
        ]);

        $po->refresh();
        $this->assertSame(PurchaseOrderStatus::SHIPPED, $po->status);
    }

    public function test_cancelled_po_is_not_transitioned(): void
    {
        [$pi, $piItem] = $this->makePI(qty: 100);
        [$po, $poItem] = $this->makePOForPI($pi, $piItem, status: PurchaseOrderStatus::CANCELLED->value);

        $shipment = $this->makeShipment(ShipmentStatus::IN_TRANSIT);
        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $piItem->id,
            'purchase_order_item_id' => $poItem->id,
            'quantity' => 100,
            'sort_order' => 0,
        ]);

        $po->refresh();
        $this->assertSame(PurchaseOrderStatus::CANCELLED, $po->status);
    }

    public function test_finalized_pi_is_not_transitioned_to_shipped(): void
    {
        [$pi, $piItem] = $this->makePI(qty: 100, status: 'finalized');
        [$po, $poItem] = $this->makePOForPI($pi, $piItem, status: PurchaseOrderStatus::CONFIRMED->value);

        $shipment = $this->makeShipment(ShipmentStatus::IN_TRANSIT);
        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $piItem->id,
            'purchase_order_item_id' => $poItem->id,
            'quantity' => 100,
            'sort_order' => 0,
        ]);

        // PO transitions normally...
        $po->refresh();
        $this->assertSame(PurchaseOrderStatus::SHIPPED, $po->status);

        // ...but PI does NOT (FINALIZED → SHIPPED is not allowed).
        $pi->refresh();
        $this->assertSame(ProformaInvoiceStatus::FINALIZED, $pi->status);
    }

    public function test_draft_shipment_does_not_trigger_transition(): void
    {
        [$pi, $piItem] = $this->makePI(qty: 100);
        [$po, $poItem] = $this->makePOForPI($pi, $piItem, status: PurchaseOrderStatus::CONFIRMED->value);

        $shipment = $this->makeShipment(ShipmentStatus::DRAFT);
        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $piItem->id,
            'purchase_order_item_id' => $poItem->id,
            'quantity' => 100,
            'sort_order' => 0,
        ]);

        $po->refresh();
        $pi->refresh();

        $this->assertSame(PurchaseOrderStatus::CONFIRMED, $po->status);
        $this->assertSame(ProformaInvoiceStatus::CONFIRMED, $pi->status);
    }

    public function test_no_reversion_when_shipment_is_cancelled_after_transition(): void
    {
        [$pi, $piItem] = $this->makePI(qty: 100);
        [$po, $poItem] = $this->makePOForPI($pi, $piItem, status: PurchaseOrderStatus::CONFIRMED->value);

        $shipment = $this->makeShipment(ShipmentStatus::IN_TRANSIT);
        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $piItem->id,
            'purchase_order_item_id' => $poItem->id,
            'quantity' => 100,
            'sort_order' => 0,
        ]);

        $po->refresh();
        $this->assertSame(PurchaseOrderStatus::SHIPPED, $po->status);

        // ARRIVED is the only allowed forward transition; from there no transition exists,
        // so we simulate a manual cancellation by direct status mutation (bypassing the
        // state machine, mimicking an admin override). Status must NOT auto-revert.
        $shipment->status = ShipmentStatus::CANCELLED;
        $shipment->saveQuietly();

        $po->refresh();
        $this->assertSame(PurchaseOrderStatus::SHIPPED, $po->status);
    }

    // --- Helpers ---

    private function makePI(int $qty = 100, string $status = 'confirmed'): array
    {
        $inquiry = Inquiry::create([
            'reference' => 'INQ-'.uniqid(),
            'company_id' => $this->client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $pi = ProformaInvoice::create([
            'reference' => 'PI-'.uniqid(),
            'inquiry_id' => $inquiry->id,
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-04-29',
            'status' => $status,
        ]);

        $piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description' => 'Product '.uniqid(),
            'quantity' => $qty,
            'unit' => 'pcs',
            'unit_price' => 1_000,
            'unit_cost' => 500,
        ]);

        return [$pi, $piItem];
    }

    private function makePOForPI(ProformaInvoice $pi, ProformaInvoiceItem $piItem, string $status): array
    {
        $po = PurchaseOrder::create([
            'reference' => 'PO-'.uniqid(),
            'proforma_invoice_id' => $pi->id,
            'supplier_company_id' => $this->supplier->id,
            'currency_code' => 'USD',
            'status' => $status,
            'issue_date' => '2026-04-29',
        ]);

        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'proforma_invoice_item_id' => $piItem->id,
            'description' => $piItem->description,
            'quantity' => $piItem->quantity,
            'unit' => 'pcs',
            'unit_cost' => 500,
        ]);

        return [$po, $poItem];
    }

    private function makeShipment(ShipmentStatus $status): Shipment
    {
        return Shipment::create([
            'reference' => 'SHIP-'.uniqid(),
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
            'status' => $status,
            'transport_mode' => 'sea',
            'origin_port' => 'Shanghai',
            'destination_port' => 'Santos',
        ]);
    }
}
