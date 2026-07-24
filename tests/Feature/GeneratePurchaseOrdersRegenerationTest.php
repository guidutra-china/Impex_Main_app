<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Actions\GeneratePurchaseOrdersAction;
use App\Domain\PurchaseOrders\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use Database\Factories\ProformaInvoiceItemFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeneratePurchaseOrdersRegenerationTest extends TestCase
{
    use RefreshDatabase;

    private function makePiWithItems(Company $supplier, int $itemCount = 2): ProformaInvoice
    {
        $pi = ProformaInvoice::factory()->create(['status' => 'confirmed']);

        for ($i = 0; $i < $itemCount; $i++) {
            ProformaInvoiceItemFactory::new()->create([
                'proforma_invoice_id' => $pi->id,
                'supplier_company_id' => $supplier->id,
                'product_id' => Product::factory(),
                'sort_order' => $i,
            ]);
        }

        return $pi->fresh('items');
    }

    public function test_regenerating_does_not_duplicate_items(): void
    {
        $supplier = Company::factory()->create();
        $pi = $this->makePiWithItems($supplier, 2);

        $action = new GeneratePurchaseOrdersAction;

        $action->execute($pi);
        $action->execute($pi->fresh('items'));

        $po = PurchaseOrder::where('proforma_invoice_id', $pi->id)
            ->where('supplier_company_id', $supplier->id)
            ->firstOrFail();

        $this->assertSame(2, $po->items()->count(), 'Regeneration should update in place, not duplicate items.');
    }

    public function test_regenerating_propagates_pi_item_modifications_to_the_po(): void
    {
        $supplier = Company::factory()->create();
        $productA = Product::factory()->create();
        $productB = Product::factory()->create();

        $pi = ProformaInvoice::factory()->create(['status' => 'confirmed']);
        $item = ProformaInvoiceItemFactory::new()->create([
            'proforma_invoice_id' => $pi->id,
            'supplier_company_id' => $supplier->id,
            'product_id' => $productA->id,
            'description' => 'Original description',
            'quantity' => 100,
            'sort_order' => 0,
        ]);

        $action = new GeneratePurchaseOrdersAction;
        $action->execute($pi->fresh('items'));

        // Simulate editing the PI: change quantity, swap product, change description.
        $item->update([
            'product_id' => $productB->id,
            'description' => 'Updated description',
            'quantity' => 250,
        ]);

        $action->execute($pi->fresh('items'));

        $po = PurchaseOrder::where('proforma_invoice_id', $pi->id)
            ->where('supplier_company_id', $supplier->id)
            ->firstOrFail();

        $this->assertSame(1, $po->items()->count(), 'Modifying an item must not add a new PO line.');

        $poItem = $po->items()->firstOrFail();
        $this->assertSame($productB->id, $poItem->product_id, 'Swapped product must propagate to the PO.');
        $this->assertSame(250, $poItem->quantity, 'Changed quantity must propagate to the PO.');
        $this->assertSame('Updated description', $poItem->description, 'Changed description must propagate to the PO.');
    }

    public function test_regenerating_after_adding_a_pi_item_does_not_duplicate_existing_items(): void
    {
        $supplier = Company::factory()->create();
        $pi = $this->makePiWithItems($supplier, 2);

        $action = new GeneratePurchaseOrdersAction;
        $action->execute($pi);

        // Simulate user editing the PI: add one more line item (existing items keep their ids).
        ProformaInvoiceItemFactory::new()->create([
            'proforma_invoice_id' => $pi->id,
            'supplier_company_id' => $supplier->id,
            'product_id' => Product::factory(),
            'sort_order' => 2,
        ]);

        $action->execute($pi->fresh('items'));

        $po = PurchaseOrder::where('proforma_invoice_id', $pi->id)
            ->where('supplier_company_id', $supplier->id)
            ->firstOrFail();

        $this->assertSame(3, $po->items()->count(), 'Should have exactly 3 items after adding one and regenerating.');
    }

    public function test_regenerating_after_deleting_and_readding_a_pi_item_does_not_duplicate(): void
    {
        $supplier = Company::factory()->create();
        $product = Product::factory()->create();

        $pi = ProformaInvoice::factory()->create(['status' => 'confirmed']);
        $itemA = ProformaInvoiceItemFactory::new()->create([
            'proforma_invoice_id' => $pi->id,
            'supplier_company_id' => $supplier->id,
            'product_id' => $product->id,
            'sort_order' => 0,
        ]);

        $action = new GeneratePurchaseOrdersAction;
        $action->execute($pi->fresh('items'));

        // Simulate user editing the PI: remove the line (FK sets the PO item's
        // proforma_invoice_item_id to NULL) and re-add the same product.
        $itemA->delete();
        ProformaInvoiceItemFactory::new()->create([
            'proforma_invoice_id' => $pi->id,
            'supplier_company_id' => $supplier->id,
            'product_id' => $product->id,
            'sort_order' => 0,
        ]);

        $action->execute($pi->fresh('items'));

        $po = PurchaseOrder::where('proforma_invoice_id', $pi->id)
            ->where('supplier_company_id', $supplier->id)
            ->firstOrFail();

        $this->assertSame(1, $po->items()->count(), 'PI has one line; the PO must not keep a stale NULL-linked duplicate.');
    }

    public function test_deleting_a_pi_item_preserves_manually_added_po_items(): void
    {
        $supplier = Company::factory()->create();
        $pi = $this->makePiWithItems($supplier, 1);

        $action = new GeneratePurchaseOrdersAction;
        $action->execute($pi);

        $po = PurchaseOrder::where('proforma_invoice_id', $pi->id)
            ->where('supplier_company_id', $supplier->id)
            ->firstOrFail();

        // A manually-added PO line (no PI link) must never be touched by PI edits.
        $manual = \App\Domain\PurchaseOrders\Models\PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'proforma_invoice_item_id' => null,
            'product_id' => Product::factory()->create()->id,
            'description' => 'Manual line',
            'quantity' => 10,
            'unit' => 'pcs',
            'unit_cost' => 1000,
            'sort_order' => 99,
        ]);

        // Remove the PI line that generated the linked PO item.
        $pi->items()->first()->delete();

        $this->assertModelExists($manual->fresh());
        $this->assertSame(1, $po->items()->count(), 'Only the manual line should remain.');
        $this->assertNull($po->items()->first()->proforma_invoice_item_id);
    }

    /**
     * Build a PI with one item, generate the PO, and move it to the given status.
     *
     * @return array{0: ProformaInvoice, 1: PurchaseOrder}
     */
    private function makePiWithPoInStatus(Company $supplier, PurchaseOrderStatus $status, int $itemCount = 1): array
    {
        $pi = $this->makePiWithItems($supplier, $itemCount);

        (new GeneratePurchaseOrdersAction)->execute($pi);

        $po = PurchaseOrder::where('proforma_invoice_id', $pi->id)
            ->where('supplier_company_id', $supplier->id)
            ->firstOrFail();

        $po->forceFill(['status' => $status])->save();

        return [$pi, $po];
    }

    public function test_confirmed_po_is_not_synced_without_update_confirmed_flag(): void
    {
        $supplier = Company::factory()->create();
        [$pi, $po] = $this->makePiWithPoInStatus($supplier, PurchaseOrderStatus::CONFIRMED);

        $originalQuantity = $po->items()->firstOrFail()->quantity;
        $pi->items()->first()->update(['quantity' => $originalQuantity + 500]);

        (new GeneratePurchaseOrdersAction)->execute($pi->fresh('items'));

        $this->assertSame(
            $originalQuantity,
            $po->items()->firstOrFail()->fresh()->quantity,
            'A confirmed PO must stay untouched when the flag is not given.',
        );
    }

    public function test_confirmed_po_is_synced_when_update_confirmed_requested(): void
    {
        $supplier = Company::factory()->create();
        [$pi, $po] = $this->makePiWithPoInStatus($supplier, PurchaseOrderStatus::CONFIRMED);

        $pi->items()->first()->update(['quantity' => 777, 'description' => 'Edited after confirm']);

        $result = (new GeneratePurchaseOrdersAction)->execute($pi->fresh('items'), updateConfirmed: true);

        $poItem = $po->items()->firstOrFail()->fresh();
        $this->assertSame(777, $poItem->quantity, 'Confirmed PO must sync when explicitly requested.');
        $this->assertSame('Edited after confirm', $poItem->description);
        $this->assertTrue($result->contains(fn ($r) => $r->id === $po->id), 'Synced PO must be reported in the result.');
    }

    public function test_in_production_and_awaiting_shipment_pos_are_synced_with_flag(): void
    {
        foreach ([PurchaseOrderStatus::IN_PRODUCTION, PurchaseOrderStatus::AWAITING_SHIPMENT] as $status) {
            $supplier = Company::factory()->create();
            [$pi, $po] = $this->makePiWithPoInStatus($supplier, $status);

            $pi->items()->first()->update(['quantity' => 333]);

            (new GeneratePurchaseOrdersAction)->execute($pi->fresh('items'), updateConfirmed: true);

            $this->assertSame(
                333,
                $po->items()->firstOrFail()->fresh()->quantity,
                "A {$status->value} PO must sync when explicitly requested.",
            );
        }
    }

    public function test_shipped_po_is_never_synced_even_with_flag(): void
    {
        $supplier = Company::factory()->create();
        [$pi, $po] = $this->makePiWithPoInStatus($supplier, PurchaseOrderStatus::SHIPPED);

        $originalQuantity = $po->items()->firstOrFail()->quantity;
        $pi->items()->first()->update(['quantity' => $originalQuantity + 500]);

        (new GeneratePurchaseOrdersAction)->execute($pi->fresh('items'), updateConfirmed: true);

        $this->assertSame(
            $originalQuantity,
            $po->items()->firstOrFail()->fresh()->quantity,
            'A shipped PO must never be synced.',
        );
    }

    public function test_sync_skips_item_when_new_quantity_is_below_shipped_and_reports_it(): void
    {
        $supplier = Company::factory()->create();
        [$pi, $po] = $this->makePiWithPoInStatus($supplier, PurchaseOrderStatus::CONFIRMED);

        $poItem = $po->items()->firstOrFail();

        ShipmentItem::create([
            'shipment_id' => Shipment::factory()->create()->id,
            'proforma_invoice_item_id' => $poItem->proforma_invoice_item_id,
            'purchase_order_item_id' => $poItem->id,
            'quantity' => 100,
            'unit' => 'pcs',
        ]);

        $originalDescription = $poItem->description;

        // New PI quantity (50) is below the 100 already shipped: skip the whole item.
        $pi->items()->first()->update(['quantity' => 50, 'description' => 'Should not propagate']);

        $action = new GeneratePurchaseOrdersAction;
        $action->execute($pi->fresh('items'), updateConfirmed: true);

        $freshPoItem = $poItem->fresh();
        $this->assertSame($poItem->quantity, $freshPoItem->quantity, 'Quantity below shipped must not be applied.');
        $this->assertSame($originalDescription, $freshPoItem->description, 'A skipped item must not be partially updated.');

        $skipped = $action->getSkippedShippedItems();
        $this->assertCount(1, $skipped);
        $this->assertSame($poItem->id, $skipped->first()['po_item']->id);
        $this->assertSame(100, $skipped->first()['shipped']);
        $this->assertSame(50, $skipped->first()['requested']);
    }

    public function test_sync_does_not_delete_po_items_referenced_by_production_schedule_entries(): void
    {
        $supplier = Company::factory()->create();
        [$pi, $po] = $this->makePiWithPoInStatus($supplier, PurchaseOrderStatus::CONFIRMED, itemCount: 2);

        $movedPiItem = $pi->items()->orderBy('sort_order')->firstOrFail();
        $poItem = $po->items()->where('proforma_invoice_item_id', $movedPiItem->id)->firstOrFail();

        \Database\Factories\ProductionScheduleEntryFactory::new()->create([
            'proforma_invoice_item_id' => $poItem->proforma_invoice_item_id,
            'purchase_order_item_id' => $poItem->id,
        ]);

        // Move one PI item to another supplier: it leaves this PO's item set while
        // the other item keeps the supplier group alive, so the cleanup path runs.
        $movedPiItem->update(['supplier_company_id' => Company::factory()->create()->id]);

        (new GeneratePurchaseOrdersAction)->execute($pi->fresh('items'), updateConfirmed: true);

        $this->assertModelExists(
            $poItem->fresh(),
            'A PO item referenced by production schedule entries must survive the cleanup.',
        );
    }
}
