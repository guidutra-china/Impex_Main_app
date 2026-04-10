<?php

namespace Tests\Feature;

use App\Domain\Catalog\Actions\ProductDeletionGuard;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Inquiries\Models\InquiryItem;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\PurchaseOrders\Models\PurchaseOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDeletionGuardTest extends TestCase
{
    use RefreshDatabase;

    private ProductDeletionGuard $guard;
    private Company $client;
    private Inquiry $inquiry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new ProductDeletionGuard();
        $this->client = Company::create(['name' => 'Test Client', 'status' => 'active']);
        $this->inquiry = Inquiry::create([
            'reference' => 'INQ-GUARD-' . uniqid(),
            'company_id' => $this->client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);
    }

    private function createProduct(): Product
    {
        return Product::create([
            'name' => 'Test Product ' . uniqid(),
            'sku' => 'SKU-' . uniqid(),
        ]);
    }

    private function createPiWithItem(Product $product, string $status = 'draft'): array
    {
        $pi = ProformaInvoice::create([
            'reference' => 'PI-GUARD-' . uniqid(),
            'inquiry_id' => $this->inquiry->id,
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
            'issue_date' => now()->toDateString(),
            'status' => $status,
        ]);

        $piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'product_id' => $product->id,
            'description' => 'Test item',
            'quantity' => 100,
            'unit_price' => 1000,
            'unit' => 'pcs',
        ]);

        return [$pi, $piItem];
    }

    public function test_product_without_references_can_be_deleted(): void
    {
        $product = $this->createProduct();

        $blocking = $this->guard->check($product);

        $this->assertTrue($blocking->isEmpty());
        $this->assertTrue($product->delete());
        $this->assertSoftDeleted($product);
    }

    public function test_product_with_active_proforma_invoice_is_blocked(): void
    {
        $product = $this->createProduct();
        [$pi] = $this->createPiWithItem($product, 'confirmed');

        $blocking = $this->guard->check($product);

        $this->assertFalse($blocking->isEmpty());
        $this->assertTrue($blocking->contains(fn ($msg) => str_contains($msg, $pi->reference)));
    }

    public function test_product_with_cancelled_proforma_invoice_can_be_deleted(): void
    {
        $product = $this->createProduct();
        $this->createPiWithItem($product, 'cancelled');

        $blocking = $this->guard->check($product);

        $this->assertTrue($blocking->isEmpty());
    }

    public function test_product_with_finalized_proforma_invoice_can_be_deleted(): void
    {
        $product = $this->createProduct();
        $this->createPiWithItem($product, 'finalized');

        $blocking = $this->guard->check($product);

        $this->assertTrue($blocking->isEmpty());
    }

    public function test_product_with_active_shipment_is_blocked(): void
    {
        $product = $this->createProduct();
        [$pi, $piItem] = $this->createPiWithItem($product, 'finalized');

        $shipment = Shipment::create([
            'reference' => 'SHIP-GUARD-' . uniqid(),
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
            'status' => 'in_transit',
        ]);
        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $piItem->id,
            'quantity' => 100,
        ]);

        $blocking = $this->guard->check($product);

        $this->assertFalse($blocking->isEmpty());
        $this->assertTrue($blocking->contains(fn ($msg) => str_contains($msg, $shipment->reference)));
    }

    public function test_product_with_cancelled_shipment_is_not_blocked(): void
    {
        $product = $this->createProduct();
        [$pi, $piItem] = $this->createPiWithItem($product, 'finalized');

        $shipment = Shipment::create([
            'reference' => 'SHIP-GUARD-' . uniqid(),
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
            'status' => 'cancelled',
        ]);
        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $piItem->id,
            'quantity' => 100,
        ]);

        $blocking = $this->guard->check($product);

        // PI is finalized (not blocking), shipment is cancelled (not blocking)
        $this->assertTrue($blocking->isEmpty());
    }

    public function test_product_with_active_purchase_order_is_blocked(): void
    {
        $product = $this->createProduct();
        [$pi, $piItem] = $this->createPiWithItem($product, 'finalized');

        $po = PurchaseOrder::create([
            'reference' => 'PO-GUARD-' . uniqid(),
            'proforma_invoice_id' => $pi->id,
            'supplier_company_id' => $this->client->id,
            'status' => 'in_production',
            'currency_code' => 'USD',
            'issue_date' => now()->toDateString(),
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'proforma_invoice_item_id' => $piItem->id,
            'description' => 'Test',
            'quantity' => 100,
            'unit_cost' => 1000,
        ]);

        $blocking = $this->guard->check($product);

        $this->assertFalse($blocking->isEmpty());
        $this->assertTrue($blocking->contains(fn ($msg) => str_contains($msg, $po->reference)));
    }

    public function test_product_with_multiple_active_references_lists_all(): void
    {
        $product = $this->createProduct();

        // Active PI
        [$pi, $piItem] = $this->createPiWithItem($product, 'confirmed');

        // Active PO
        $po = PurchaseOrder::create([
            'reference' => 'PO-GUARD-' . uniqid(),
            'proforma_invoice_id' => $pi->id,
            'supplier_company_id' => $this->client->id,
            'status' => 'sent',
            'currency_code' => 'USD',
            'issue_date' => now()->toDateString(),
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'proforma_invoice_item_id' => $piItem->id,
            'description' => 'Test',
            'quantity' => 100,
            'unit_cost' => 1000,
        ]);

        $blocking = $this->guard->check($product);

        $this->assertGreaterThanOrEqual(2, $blocking->count());
        $this->assertTrue($blocking->contains(fn ($msg) => str_contains($msg, $pi->reference)));
        $this->assertTrue($blocking->contains(fn ($msg) => str_contains($msg, $po->reference)));
    }

    public function test_model_deleting_event_prevents_deletion(): void
    {
        $product = $this->createProduct();
        $this->createPiWithItem($product, 'draft');

        $result = $product->delete();

        $this->assertFalse($result);
        $this->assertNull($product->fresh()->deleted_at);
    }
}
