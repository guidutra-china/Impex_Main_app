<?php

namespace Tests\Feature\Logistics;

use App\Domain\CRM\Models\Company;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\PurchaseOrders\Models\PurchaseOrderItem;
use App\Filament\Resources\PurchaseOrders\Widgets\POShipmentFulfillmentWidget;
use App\Filament\SupplierPortal\Resources\PurchaseOrderResource\Widgets\SupplierPOShipmentFulfillmentWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O fallback por item de PI existe para o caso do FK `set null`: editar/regerar
 * uma PO apaga a linha antiga e o item de embarque perde o vínculo. Só que o
 * fallback não era filtrado por PO — com a linha da PI dividida em duas POs,
 * cada uma reivindicava TODOS os embarques daquela linha e as duas apareciam
 * com o dobro embarcado (caso PI-2026-00049 / PO-39 / PO-52).
 */
class PoShipmentFulfillmentSplitPoTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    private ProformaInvoiceItem $piItem;

    private function makeScenario(int $piQuantity): void
    {
        $this->client = Company::factory()->create();

        $pi = ProformaInvoice::factory()->create([
            'company_id' => $this->client->id,
            'status' => 'confirmed',
        ]);

        $this->piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description' => 'Chest Press',
            'quantity' => $piQuantity,
            'unit_price' => 150000,
            'sort_order' => 1,
        ]);
    }

    private function makePoLine(int $quantity): PurchaseOrderItem
    {
        $po = PurchaseOrder::factory()->create([
            'proforma_invoice_id' => $this->piItem->proforma_invoice_id,
            'supplier_company_id' => Company::factory()->create()->id,
            'status' => 'sent',
            'currency_code' => 'USD',
        ]);

        return PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'proforma_invoice_item_id' => $this->piItem->id,
            'description' => 'Chest Press',
            'quantity' => $quantity,
            'unit_cost' => 100000,
            'sort_order' => 1,
        ]);
    }

    private function ship(string $reference, ?PurchaseOrderItem $poItem, int $quantity, ShipmentStatus $status = ShipmentStatus::IN_TRANSIT): Shipment
    {
        $shipment = Shipment::factory()->create([
            'company_id' => $this->client->id,
            'reference' => $reference,
            'status' => $status,
            'currency_code' => 'USD',
        ]);

        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $this->piItem->id,
            'purchase_order_item_id' => $poItem?->id,
            'quantity' => $quantity,
            'sort_order' => 1,
        ]);

        return $shipment;
    }

    /** @return array{quantity: int, shipped: int, remaining: int, shipment_refs: list<string>} */
    private function adminRow(PurchaseOrder $po): array
    {
        $widget = new POShipmentFulfillmentWidget;
        $widget->record = $po->fresh();

        return $this->viewData($widget)['items'][0];
    }

    /** @return array{quantity: int, shipped: int, remaining: int, shipment_refs: list<string>} */
    private function supplierRow(PurchaseOrder $po): array
    {
        $widget = new SupplierPOShipmentFulfillmentWidget;
        $widget->record = $po->fresh();

        return $this->viewData($widget)['items'][0];
    }

    private function viewData(object $widget): array
    {
        $method = new \ReflectionMethod($widget, 'getViewData');
        $method->setAccessible(true);

        return $method->invoke($widget);
    }

    public function test_each_po_of_a_split_pi_item_only_counts_its_own_shipment(): void
    {
        $this->makeScenario(2);
        $first = $this->makePoLine(1);
        $second = $this->makePoLine(1);

        $this->ship('SH-A', $first, 1);
        $this->ship('SH-B', $second, 1);

        $rowA = $this->adminRow($first->purchaseOrder);
        $this->assertSame(1, $rowA['shipped']);
        $this->assertSame(['SH-A'], $rowA['shipment_refs']);

        $rowB = $this->adminRow($second->purchaseOrder);
        $this->assertSame(1, $rowB['shipped']);
        $this->assertSame(['SH-B'], $rowB['shipment_refs']);
    }

    public function test_supplier_portal_widget_also_counts_only_its_own_shipment(): void
    {
        $this->makeScenario(2);
        $first = $this->makePoLine(1);
        $second = $this->makePoLine(1);

        $this->ship('SH-A', $first, 1);
        $this->ship('SH-B', $second, 1);

        $this->assertSame(1, $this->supplierRow($first->purchaseOrder)['shipped']);
        $this->assertSame(1, $this->supplierRow($second->purchaseOrder)['shipped']);
    }

    public function test_orphaned_link_still_counts_when_a_single_po_covers_the_pi_item(): void
    {
        $this->makeScenario(10);
        $poItem = $this->makePoLine(10);

        // FK `set null`: a PO foi reeditada e o embarque perdeu o vínculo.
        $this->ship('SH-A', null, 4);

        $row = $this->adminRow($poItem->purchaseOrder);
        $this->assertSame(4, $row['shipped']);
        $this->assertSame(['SH-A'], $row['shipment_refs']);
        $this->assertSame(4, $this->supplierRow($poItem->purchaseOrder)['shipped']);
    }

    public function test_orphaned_link_is_not_attributed_to_either_po_of_a_split_item(): void
    {
        $this->makeScenario(2);
        $first = $this->makePoLine(1);
        $second = $this->makePoLine(1);

        // Órfão numa linha dividida: não dá para saber de qual PO é. Melhor
        // subnotificar do que creditar 100% às duas.
        $this->ship('SH-A', null, 1);

        $this->assertSame(0, $this->adminRow($first->purchaseOrder)['shipped']);
        $this->assertSame(0, $this->adminRow($second->purchaseOrder)['shipped']);
        $this->assertSame(0, $this->supplierRow($first->purchaseOrder)['shipped']);
        $this->assertSame(0, $this->supplierRow($second->purchaseOrder)['shipped']);
    }

    public function test_direct_link_and_orphan_are_summed_not_alternated(): void
    {
        $this->makeScenario(10);
        $poItem = $this->makePoLine(10);

        $this->ship('SH-A', $poItem, 3);
        $this->ship('SH-B', null, 2);

        // O fallback antigo era um `else`: com link direto > 0 ele ignorava o
        // órfão e reportava 3 em vez de 5.
        $row = $this->adminRow($poItem->purchaseOrder);
        $this->assertSame(5, $row['shipped']);
        $this->assertEqualsCanonicalizing(['SH-A', 'SH-B'], $row['shipment_refs']);
    }

    public function test_draft_shipment_does_not_count_as_shipped(): void
    {
        $this->makeScenario(10);
        $poItem = $this->makePoLine(10);

        $this->ship('SH-A', $poItem, 4, ShipmentStatus::DRAFT);

        $this->assertSame(0, $this->adminRow($poItem->purchaseOrder)['shipped']);
        $this->assertSame(0, $this->supplierRow($poItem->purchaseOrder)['shipped']);
    }

    public function test_deleted_shipment_does_not_count_as_shipped(): void
    {
        $this->makeScenario(10);
        $poItem = $this->makePoLine(10);

        $this->ship('SH-A', $poItem, 4)->delete();

        $this->assertSame(0, $this->adminRow($poItem->purchaseOrder)['shipped']);
        // O portal do fornecedor usava withoutGlobalScopes(), que derruba junto
        // o filtro de soft delete — embarque apagado continuava contando.
        $this->assertSame(0, $this->supplierRow($poItem->purchaseOrder)['shipped']);
    }
}
