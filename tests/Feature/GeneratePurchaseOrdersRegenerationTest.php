<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Actions\GeneratePurchaseOrdersAction;
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
}
