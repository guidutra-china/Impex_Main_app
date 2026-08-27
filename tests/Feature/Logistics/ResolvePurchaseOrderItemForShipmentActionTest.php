<?php

namespace Tests\Feature\Logistics;

use App\Domain\CRM\Models\Company;
use App\Domain\Logistics\Actions\ResolvePurchaseOrderItemForShipmentAction;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\PurchaseOrders\Models\PurchaseOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O resolvedor antigo era `->first()`: com a linha da PI dividida em duas POs,
 * todo embarque grudava na PO mais antiga. Aqui o critério é saldo — a PO só
 * recebe o item de embarque se ainda tiver quantidade não embarcada.
 */
class ResolvePurchaseOrderItemForShipmentActionTest extends TestCase
{
    use RefreshDatabase;

    private ProformaInvoiceItem $piItem;

    private Company $client;

    private function makePiItem(int $quantity): ProformaInvoiceItem
    {
        $this->client = Company::factory()->create();

        $pi = ProformaInvoice::factory()->create([
            'company_id' => $this->client->id,
            'status' => 'confirmed',
        ]);

        return $this->piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description' => 'Chest Press',
            'quantity' => $quantity,
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

    private function ship(PurchaseOrderItem $poItem, int $quantity, ShipmentStatus $status = ShipmentStatus::IN_TRANSIT): ShipmentItem
    {
        $shipment = Shipment::factory()->create([
            'company_id' => $this->client->id,
            'status' => $status,
            'currency_code' => 'USD',
        ]);

        return ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $this->piItem->id,
            'purchase_order_item_id' => $poItem->id,
            'quantity' => $quantity,
            'sort_order' => 1,
        ]);
    }

    private function resolve(int $quantity = 1, ?int $ignoreShipmentItemId = null): ?PurchaseOrderItem
    {
        return app(ResolvePurchaseOrderItemForShipmentAction::class)
            ->execute($this->piItem->id, $quantity, $ignoreShipmentItemId);
    }

    public function test_returns_null_when_the_pi_item_has_no_po(): void
    {
        $this->makePiItem(2);

        $this->assertNull($this->resolve());
    }

    public function test_returns_the_only_po_line_when_there_is_no_split(): void
    {
        $this->makePiItem(10);
        $poItem = $this->makePoLine(10);

        $this->assertSame($poItem->id, $this->resolve(4)?->id);
    }

    public function test_returns_the_only_po_line_even_when_it_is_already_full(): void
    {
        $this->makePiItem(10);
        $poItem = $this->makePoLine(10);
        $this->ship($poItem, 10);

        // Sem alternativa, o vínculo tem que existir de qualquer forma — quem
        // acusa o excesso é o widget de fulfillment, não o resolvedor.
        $this->assertSame($poItem->id, $this->resolve(1)?->id);
    }

    public function test_prefers_the_first_po_line_while_it_still_has_room(): void
    {
        $this->makePiItem(2);
        $first = $this->makePoLine(1);
        $this->makePoLine(1);

        $this->assertSame($first->id, $this->resolve(1)?->id);
    }

    public function test_moves_to_the_second_po_line_once_the_first_is_covered(): void
    {
        $this->makePiItem(2);
        $first = $this->makePoLine(1);
        $second = $this->makePoLine(1);

        $this->ship($first, 1);

        $this->assertSame($second->id, $this->resolve(1)?->id);
    }

    public function test_draft_shipments_also_consume_the_po_line(): void
    {
        $this->makePiItem(2);
        $first = $this->makePoLine(1);
        $second = $this->makePoLine(1);

        // Um rascunho ainda segura a alocação daquela PO: sem isso, dois
        // rascunhos disputariam a mesma linha.
        $this->ship($first, 1, ShipmentStatus::DRAFT);

        $this->assertSame($second->id, $this->resolve(1)?->id);
    }

    public function test_deleted_shipments_do_not_consume_the_po_line(): void
    {
        $this->makePiItem(2);
        $first = $this->makePoLine(1);
        $this->makePoLine(1);

        $shipItem = $this->ship($first, 1);
        $shipItem->shipment->delete();

        $this->assertSame($first->id, $this->resolve(1)?->id);
    }

    public function test_the_shipment_item_being_edited_does_not_consume_its_own_line(): void
    {
        $this->makePiItem(2);
        $first = $this->makePoLine(1);
        $this->makePoLine(1);

        $shipItem = $this->ship($first, 1);

        // Reeditar a mesma linha não pode empurrá-la para a PO seguinte.
        $this->assertSame($first->id, $this->resolve(1, $shipItem->id)?->id);
    }

    public function test_falls_back_to_a_line_with_partial_room_when_nothing_fits_whole(): void
    {
        $this->makePiItem(6);
        $first = $this->makePoLine(2);
        $second = $this->makePoLine(4);

        $this->ship($first, 2);
        $this->ship($second, 3);

        // Nenhuma comporta 3 inteiras; a segunda ainda tem 1 de saldo.
        $this->assertSame($second->id, $this->resolve(3)?->id);
    }

    public function test_falls_back_to_the_first_line_when_every_po_is_full(): void
    {
        $this->makePiItem(2);
        $first = $this->makePoLine(1);
        $second = $this->makePoLine(1);

        $this->ship($first, 1);
        $this->ship($second, 1);

        $this->assertSame($first->id, $this->resolve(1)?->id);
    }

    public function test_ignores_po_lines_whose_po_was_deleted(): void
    {
        $this->makePiItem(2);
        $first = $this->makePoLine(1);
        $second = $this->makePoLine(1);

        $first->purchaseOrder->delete();

        $this->assertSame($second->id, $this->resolve(1)?->id);
    }
}
