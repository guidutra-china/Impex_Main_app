<?php

namespace Tests\Feature\Logistics;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPackaging;
use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Actions\BulkFillAction;
use App\Domain\Logistics\Actions\CreateContainerAction;
use App\Domain\Logistics\Actions\CreatePalletAction;
use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Models\CartonContent;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class BulkFillActionTest extends TestCase
{
    use RefreshDatabase;

    private BulkFillAction $fill;

    private CreateContainerAction $createContainer;

    private CreatePalletAction $createPallet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fill = app(BulkFillAction::class);
        $this->createContainer = app(CreateContainerAction::class);
        $this->createPallet = app(CreatePalletAction::class);
    }

    private function makeShipmentWithProduct(int $qty, int $pcsPerCarton = 2, ?float $cartonWeight = 5.0): array
    {
        $client = Company::create(['name' => 'Client BF-'.uniqid(), 'status' => 'active']);
        $client->companyRoles()->create(['role' => 'client']);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-BF-'.uniqid(),
            'company_id' => $client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $pi = ProformaInvoice::create([
            'reference' => 'PI-BF-'.uniqid(),
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-04-09',
            'status' => 'confirmed',
        ]);

        $product = Product::create([
            'name' => 'Sandals',
            'sku' => 'SKU-BF-'.uniqid(),
        ]);

        ProductPackaging::create([
            'product_id' => $product->id,
            'packaging_type' => 'carton',
            'pcs_per_carton' => $pcsPerCarton,
            'carton_weight' => $cartonWeight,
            'carton_length' => 40,
            'carton_width' => 30,
            'carton_height' => 20,
        ]);

        $piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'product_id' => $product->id,
            'description' => 'Sandals',
            'quantity' => $qty,
            'unit_price' => 1000,
            'unit' => 'pcs',
        ]);

        $shipment = Shipment::create([
            'reference' => 'SHIP-BF-'.uniqid(),
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'status' => 'draft',
            'transport_mode' => 'sea',
            'origin_port' => 'Shanghai',
            'destination_port' => 'Santos',
        ]);

        $item = ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $piItem->id,
            'quantity' => $qty,
            'sort_order' => 0,
        ]);

        return [$shipment, $item];
    }

    public function test_fill_container_creates_expected_cartons(): void
    {
        [$shipment, $item] = $this->makeShipmentWithProduct(qty: 100, pcsPerCarton: 10);

        $container = $this->createContainer->execute($shipment);

        $created = $this->fill->execute($item, 100, container: $container);

        $this->assertEquals(10, $created);
        $this->assertEquals(10, $shipment->cartons()->count());

        // All cartons must be inside the container
        $this->assertEquals(10, $shipment->cartons()->where('shipment_container_id', $container->id)->count());

        // Each carton has 1 content of 10 pieces
        foreach ($shipment->cartons as $carton) {
            $this->assertEquals(1, $carton->contents->count());
            $this->assertEquals(10, $carton->contents->first()->pieces);
        }
    }

    public function test_fill_only_creates_full_cartons_leaves_remainder_unpacked(): void
    {
        [$shipment, $item] = $this->makeShipmentWithProduct(qty: 95, pcsPerCarton: 10);

        $container = $this->createContainer->execute($shipment);

        $created = $this->fill->execute($item, 95, container: $container);

        $this->assertEquals(9, $created); // 9 full only — 5 pcs remainder left unpacked

        $totalPieces = $shipment->cartons->flatMap->contents->sum('pieces');
        $this->assertEquals(90, $totalPieces); // 9 × 10

        // Remaining 5 pcs show as unpacked in progress
        $progress = app(\App\Domain\Logistics\Services\PackingProgressService::class)
            ->forShipmentItem($item);
        $this->assertEquals(90, $progress->packedComplete);
        $this->assertEquals(5, $progress->remaining());
    }

    public function test_fill_pallet_places_cartons_in_pallet_and_inherits_container(): void
    {
        [$shipment, $item] = $this->makeShipmentWithProduct(qty: 20, pcsPerCarton: 2);

        $container = $this->createContainer->execute($shipment);
        $pallet = $this->createPallet->execute($shipment, [
            'shipment_container_id' => $container->id,
        ]);

        $created = $this->fill->execute($item, 20, pallet: $pallet);

        $this->assertEquals(10, $created);

        $cartons = $shipment->cartons;
        $this->assertEquals(10, $cartons->where('shipment_pallet_id', $pallet->id)->count());
        $this->assertEquals(10, $cartons->where('shipment_container_id', $container->id)->count());
    }

    public function test_fill_loose_pallet_no_container(): void
    {
        [$shipment, $item] = $this->makeShipmentWithProduct(qty: 10, pcsPerCarton: 5);

        $pallet = $this->createPallet->execute($shipment); // loose

        $this->fill->execute($item, 10, pallet: $pallet);

        $cartons = $shipment->cartons;
        $this->assertEquals(2, $cartons->count());
        $this->assertTrue($cartons->every(fn ($c) => $c->shipment_pallet_id === $pallet->id));
        $this->assertTrue($cartons->every(fn ($c) => $c->shipment_container_id === null));
    }

    public function test_fill_reads_packaging_dimensions(): void
    {
        [$shipment, $item] = $this->makeShipmentWithProduct(qty: 10, pcsPerCarton: 5, cartonWeight: 8.0);

        $container = $this->createContainer->execute($shipment);

        $this->fill->execute($item, 10, container: $container);

        $carton = $shipment->cartons()->first();
        $this->assertEquals('8.000', $carton->gross_weight);
        $this->assertEquals('7.200', $carton->net_weight); // 90% of gross
        $this->assertEquals('40.00', $carton->length);
        $this->assertEquals('30.00', $carton->width);
        $this->assertEquals('20.00', $carton->height);
        $this->assertEquals('0.0240', $carton->volume); // 40*30*20 / 1e6
    }

    public function test_fill_respects_remaining_after_previous_fills(): void
    {
        [$shipment, $item] = $this->makeShipmentWithProduct(qty: 100, pcsPerCarton: 10);

        $contA = $this->createContainer->execute($shipment);
        $contB = $this->createContainer->execute($shipment);

        $this->fill->execute($item, 60, container: $contA);

        // Remaining is now 40 — filling another 40 works
        $this->fill->execute($item, 40, container: $contB);

        $this->assertEquals(10, $shipment->cartons()->count());
        $this->assertEquals(6, $shipment->cartons()->where('shipment_container_id', $contA->id)->count());
        $this->assertEquals(4, $shipment->cartons()->where('shipment_container_id', $contB->id)->count());
    }

    public function test_fill_rejects_overshoot(): void
    {
        [$shipment, $item] = $this->makeShipmentWithProduct(qty: 50, pcsPerCarton: 10);

        $container = $this->createContainer->execute($shipment);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('only 50 remaining');

        $this->fill->execute($item, 51, container: $container);
    }

    public function test_fill_rejects_zero_pieces(): void
    {
        [$shipment, $item] = $this->makeShipmentWithProduct(qty: 50);

        $container = $this->createContainer->execute($shipment);

        $this->expectException(InvalidArgumentException::class);
        $this->fill->execute($item, 0, container: $container);
    }

    public function test_fill_rejects_split_items(): void
    {
        [$shipment, $item] = $this->makeShipmentWithProduct(qty: 10);

        $item->update([
            'packing_split' => ['set_id' => '01HTEST000000000000000000', 'part_labels' => ['A', 'B']],
        ]);

        $container = $this->createContainer->execute($shipment);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Split items');

        $this->fill->execute($item, 10, container: $container);
    }

    public function test_fill_without_packaging_creates_single_carton(): void
    {
        // Create shipment with product without packaging metadata
        $client = Company::create(['name' => 'C', 'status' => 'active']);
        $client->companyRoles()->create(['role' => 'client']);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-'.uniqid(),
            'company_id' => $client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);
        $pi = ProformaInvoice::create([
            'reference' => 'PI-'.uniqid(),
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-04-09',
            'status' => 'confirmed',
        ]);
        $piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description' => 'Unknown item',
            'quantity' => 42,
            'unit_price' => 1000,
            'unit' => 'pcs',
        ]);
        $shipment = Shipment::create([
            'reference' => 'SHIP-'.uniqid(),
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'status' => 'draft',
            'transport_mode' => 'sea',
            'origin_port' => 'Shanghai',
            'destination_port' => 'Santos',
        ]);
        $item = ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $piItem->id,
            'quantity' => 42,
            'sort_order' => 0,
        ]);

        $container = $this->createContainer->execute($shipment);

        $this->fill->execute($item, 42, container: $container);

        $this->assertEquals(1, $shipment->cartons()->count());
        $this->assertEquals(42, $shipment->cartons->first()->contents->first()->pieces);
    }

    public function test_fill_large_quantity_performance(): void
    {
        // Simulate user's real scenario: 4000 cartons (8000 pcs, 2 pcs/carton)
        [$shipment, $item] = $this->makeShipmentWithProduct(qty: 8000, pcsPerCarton: 2);

        $container = $this->createContainer->execute($shipment);

        $start = microtime(true);
        $created = $this->fill->execute($item, 8000, container: $container);
        $duration = microtime(true) - $start;

        $this->assertEquals(4000, $created);
        $this->assertEquals(4000, $shipment->cartons()->count());

        // Should complete well under 5 seconds thanks to bulk insert
        $this->assertLessThan(5.0, $duration, "Bulk fill took {$duration}s, expected under 5s");
    }

    public function test_fill_recalc_updates_shipment_totals(): void
    {
        [$shipment, $item] = $this->makeShipmentWithProduct(qty: 20, pcsPerCarton: 10, cartonWeight: 5.0);

        $container = $this->createContainer->execute($shipment);

        $this->fill->execute($item, 20, container: $container);

        $shipment->refresh();
        $this->assertEquals(2, $shipment->total_packages);
        $this->assertEquals('10.000', $shipment->total_gross_weight);
    }
}
