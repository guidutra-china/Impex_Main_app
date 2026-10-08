<?php

namespace Tests\Feature\Shipments;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\PurchaseOrders\Models\PurchaseOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconcileShipmentItemsCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function tearDown(): void
    {
        if (isset($this->file) && is_file($this->file)) {
            unlink($this->file);
        }

        parent::tearDown();
    }

    /** @return array{0: Shipment, 1: ProformaInvoice, 2: array<string, ProformaInvoiceItem>, 3: PurchaseOrder} */
    private function scenario(): array
    {
        $client = Company::create(['name' => 'Client RC-'.uniqid(), 'status' => 'active']);
        $client->companyRoles()->create(['role' => 'client']);
        $supplier = Company::create(['name' => 'Supplier RC-'.uniqid(), 'status' => 'active']);
        $supplier->companyRoles()->create(['role' => 'supplier']);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-RC-'.uniqid(),
            'company_id' => $client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $pi = ProformaInvoice::create([
            'reference' => 'PI-RC-'.uniqid(),
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-08-01',
            'status' => 'confirmed',
        ]);

        $po = PurchaseOrder::create([
            'reference' => 'PO-RC-'.uniqid(),
            'proforma_invoice_id' => $pi->id,
            'supplier_company_id' => $supplier->id,
            'status' => 'draft',
            'currency_code' => 'USD',
        ]);

        $shipment = Shipment::create([
            'reference' => 'SH-RC-'.uniqid(),
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'status' => 'draft',
            'transport_mode' => 'sea',
            'destination_port' => 'Paranagua',
        ]);

        $items = [];

        foreach (['AAA' => 10, 'BBB' => 1000, 'CCC' => 5] as $code => $qty) {
            $product = Product::factory()->create(['reference_code' => $code]);
            $items[$code] = ProformaInvoiceItem::create([
                'proforma_invoice_id' => $pi->id,
                'product_id' => $product->id,
                'description' => "Product {$code}",
                'quantity' => $qty,
                'unit_price' => 100000,
                'unit' => 'pcs',
            ]);
        }

        return [$shipment, $pi, $items, $po];
    }

    private function poItem(PurchaseOrder $po, ProformaInvoiceItem $piItem, ?int $link): PurchaseOrderItem
    {
        return PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product_id' => $piItem->product_id,
            'proforma_invoice_item_id' => $link,
            'description' => $piItem->description,
            'quantity' => $piItem->quantity,
            'unit' => 'pcs',
            'unit_cost' => 50000,
        ]);
    }

    private function shipItem(Shipment $shipment, ProformaInvoiceItem $piItem, PurchaseOrderItem $poItem, int $qty): ShipmentItem
    {
        return ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $piItem->id,
            'purchase_order_item_id' => $poItem->id,
            'quantity' => $qty,
            'sort_order' => 0,
        ]);
    }

    private function makeFile(Shipment $shipment, ProformaInvoice $pi, array $declared): string
    {
        $items = [];

        foreach ($declared as $piItem => $qty) {
            $items[] = [
                'pi' => $pi->reference,
                'pi_item_id' => $piItem,
                'quantity' => $qty,
                'description' => 'Product',
            ];
        }

        $this->file = sys_get_temp_dir().'/reconcile-'.uniqid().'.json';
        file_put_contents($this->file, json_encode(['shipment' => $shipment->reference, 'items' => $items]));

        return $this->file;
    }

    public function test_dry_run_reports_without_writing(): void
    {
        [$shipment, $pi, $items, $po] = $this->scenario();
        $a = $this->poItem($po, $items['AAA'], $items['AAA']->id);
        $this->shipItem($shipment, $items['AAA'], $a, 10);
        $this->shipItem($shipment, $items['AAA'], $a, 10);

        $file = $this->makeFile($shipment, $pi, [$items['AAA']->id => 10]);

        $this->artisan('shipments:reconcile-items', ['file' => $file])
            ->expectsOutputToContain('Linhas repetidas a apagar: 1')
            ->assertSuccessful();

        $this->assertSame(2, ShipmentItem::where('shipment_id', $shipment->id)->count());
    }

    public function test_apply_removes_duplicates_fixes_quantity_and_creates_missing_relinking_orphan_po_line(): void
    {
        [$shipment, $pi, $items, $po] = $this->scenario();
        $a = $this->poItem($po, $items['AAA'], $items['AAA']->id);
        $b = $this->poItem($po, $items['BBB'], $items['BBB']->id);
        $orphan = $this->poItem($po, $items['CCC'], null);

        $first = $this->shipItem($shipment, $items['AAA'], $a, 10);
        $this->shipItem($shipment, $items['AAA'], $a, 10);
        $partial = $this->shipItem($shipment, $items['BBB'], $b, 1000);

        $file = $this->makeFile($shipment, $pi, [
            $items['AAA']->id => 10,
            $items['BBB']->id => 300,
            $items['CCC']->id => 5,
        ]);

        $this->artisan('shipments:reconcile-items', ['file' => $file, '--apply' => true])->assertSuccessful();

        $rows = ShipmentItem::where('shipment_id', $shipment->id)->get();
        $this->assertCount(3, $rows);
        $this->assertSame($first->id, $rows->firstWhere('proforma_invoice_item_id', $items['AAA']->id)->id);
        $this->assertSame(300, (int) $partial->refresh()->quantity);

        $created = $rows->firstWhere('proforma_invoice_item_id', $items['CCC']->id);
        $this->assertSame(5, (int) $created->quantity);
        $this->assertSame($orphan->id, $created->purchase_order_item_id);
        $this->assertSame($items['CCC']->id, $orphan->refresh()->proforma_invoice_item_id);

        // Segunda rodada: nada a fazer.
        $this->artisan('shipments:reconcile-items', ['file' => $file, '--apply' => true])
            ->expectsOutputToContain('Nada a fazer')
            ->assertSuccessful();
    }

    public function test_items_outside_the_file_are_kept_unless_delete_extras(): void
    {
        [$shipment, $pi, $items, $po] = $this->scenario();
        $a = $this->poItem($po, $items['AAA'], $items['AAA']->id);
        $b = $this->poItem($po, $items['BBB'], $items['BBB']->id);
        $this->shipItem($shipment, $items['AAA'], $a, 10);
        $this->shipItem($shipment, $items['BBB'], $b, 1000);

        $file = $this->makeFile($shipment, $pi, [$items['AAA']->id => 10]);

        $this->artisan('shipments:reconcile-items', ['file' => $file, '--apply' => true])->assertSuccessful();
        $this->assertSame(2, ShipmentItem::where('shipment_id', $shipment->id)->count());

        $this->artisan('shipments:reconcile-items', ['file' => $file, '--apply' => true, '--delete-extras' => true])->assertSuccessful();
        $this->assertSame(1, ShipmentItem::where('shipment_id', $shipment->id)->count());
    }

    public function test_aborts_without_writing_when_file_does_not_match_the_database(): void
    {
        [$shipment, $pi, $items, $po] = $this->scenario();
        $a = $this->poItem($po, $items['AAA'], $items['AAA']->id);
        $this->shipItem($shipment, $items['AAA'], $a, 10);
        $this->shipItem($shipment, $items['AAA'], $a, 10);

        $file = $this->makeFile($shipment, $pi, [$items['AAA']->id => 10, 999999 => 1]);

        $this->artisan('shipments:reconcile-items', ['file' => $file, '--apply' => true])
            ->expectsOutputToContain('não casa com este banco')
            ->assertFailed();

        $this->assertSame(2, ShipmentItem::where('shipment_id', $shipment->id)->count());
    }
}
