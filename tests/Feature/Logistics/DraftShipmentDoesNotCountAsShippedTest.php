<?php

namespace Tests\Feature\Logistics;

use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\PurchaseOrders\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\PurchaseOrders\Models\PurchaseOrderItem;
use App\Filament\Portal\Resources\ProformaInvoiceResource\Widgets\PortalShipmentFulfillmentWidget;
use App\Filament\Resources\PurchaseOrders\Widgets\POShipmentFulfillmentWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DraftShipmentDoesNotCountAsShippedTest extends TestCase
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

    public function test_pi_widget_excludes_draft_shipment_from_shipped_total(): void
    {
        [$pi, $piItem] = $this->makePI(qty: 100);

        $shipment = $this->makeShipment(ShipmentStatus::DRAFT);
        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $piItem->id,
            'quantity' => 100,
            'sort_order' => 0,
        ]);

        $widget = new PortalShipmentFulfillmentWidget;
        $widget->record = $pi->fresh();

        $data = $this->invokeViewData($widget);

        $this->assertSame(0, $data['totals']['shipped']);
        $this->assertSame(100, $data['totals']['remaining']);
        $this->assertSame('pending', $data['items'][0]['status']);

        // Sanity: o accessor canônico do model concorda.
        $piItem->refresh();
        $this->assertSame(0, $piItem->quantity_shipped);
        $this->assertSame(100, $piItem->quantity_remaining);
    }

    public function test_po_widget_excludes_draft_shipment_from_shipped_total(): void
    {
        [$pi, $piItem] = $this->makePI(qty: 100);
        [$po, $poItem] = $this->makePOForPI($pi, $piItem, PurchaseOrderStatus::CONFIRMED->value);

        $shipment = $this->makeShipment(ShipmentStatus::DRAFT);
        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $piItem->id,
            'purchase_order_item_id' => $poItem->id,
            'quantity' => 100,
            'sort_order' => 0,
        ]);

        $widget = new POShipmentFulfillmentWidget;
        $widget->record = $po->fresh();

        $data = $this->invokeViewData($widget);

        $this->assertSame(0, $data['totals']['shipped']);
        $this->assertSame(100, $data['totals']['remaining']);
        $this->assertSame([], $data['items'][0]['shipment_refs']);
    }

    public function test_remaining_in_relation_manager_query_excludes_draft_shipments(): void
    {
        [$pi, $piItem] = $this->makePI(qty: 100);

        // Cenário do bug: usuário cria um draft com 100 do item, depois apaga.
        $shipment = $this->makeShipment(ShipmentStatus::DRAFT);
        $shipmentItem = ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $piItem->id,
            'quantity' => 100,
            'sort_order' => 0,
        ]);

        // Antes de deletar: draft existe mas não deve consumir quantidade.
        $shippedWhileDraftExists = ShipmentItem::where('proforma_invoice_item_id', $piItem->id)
            ->whereHas('shipment', fn ($q) => $q->countsAsShipped())
            ->sum('quantity');
        $this->assertSame(0, (int) $shippedWhileDraftExists);

        // Depois de deletar o item do draft: continua 0; o item deve voltar a aparecer no pool.
        $shipmentItem->delete();
        $shippedAfterDelete = ShipmentItem::where('proforma_invoice_item_id', $piItem->id)
            ->whereHas('shipment', fn ($q) => $q->countsAsShipped())
            ->sum('quantity');
        $this->assertSame(0, (int) $shippedAfterDelete);

        // Quando o shipment vira IN_TRANSIT (com novo item), aí sim conta como shipped.
        $shipment->update(['status' => ShipmentStatus::IN_TRANSIT]);
        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $piItem->id,
            'quantity' => 100,
            'sort_order' => 1,
        ]);

        $shippedInTransit = ShipmentItem::where('proforma_invoice_item_id', $piItem->id)
            ->whereHas('shipment', fn ($q) => $q->countsAsShipped())
            ->sum('quantity');
        $this->assertSame(100, (int) $shippedInTransit);
    }

    // --- Helpers ---

    private function invokeViewData(object $widget): array
    {
        $method = new \ReflectionMethod($widget, 'getViewData');
        $method->setAccessible(true);

        return $method->invoke($widget);
    }

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
            'issue_date' => '2026-05-18',
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
            'issue_date' => '2026-05-18',
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
