<?php

namespace Tests\Feature\Logistics;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPackaging;
use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Actions\GeneratePackingListV2Action;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeneratePackingListV2ActionTest extends TestCase
{
    use RefreshDatabase;

    private GeneratePackingListV2Action $generate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generate = app(GeneratePackingListV2Action::class);
    }

    /**
     * Build a shipment with N products, each configured via:
     *   ['qty' => int, 'pcs_per_carton' => int?, 'carton_weight' => float?]
     */
    private function makeShipmentWithProducts(array $specs): array
    {
        $client = Company::create(['name' => 'Client GP-'.uniqid(), 'status' => 'active']);
        $client->companyRoles()->create(['role' => 'client']);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-GP-'.uniqid(),
            'company_id' => $client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $pi = ProformaInvoice::create([
            'reference' => 'PI-GP-'.uniqid(),
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-04-08',
            'status' => 'confirmed',
        ]);

        $shipment = Shipment::create([
            'reference' => 'SHIP-GP-'.uniqid(),
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'status' => 'draft',
            'transport_mode' => 'sea',
            'origin_port' => 'Shanghai',
            'destination_port' => 'Santos',
        ]);

        $items = [];
        foreach ($specs as $name => $spec) {
            $product = Product::create([
                'name' => $name,
                'sku' => 'SKU-'.$name.uniqid(),
            ]);

            if (isset($spec['pcs_per_carton']) || isset($spec['carton_weight'])) {
                ProductPackaging::create([
                    'product_id' => $product->id,
                    'packaging_type' => 'carton',
                    'pcs_per_carton' => $spec['pcs_per_carton'] ?? null,
                    'carton_weight' => $spec['carton_weight'] ?? null,
                    'carton_length' => $spec['length'] ?? null,
                    'carton_width' => $spec['width'] ?? null,
                    'carton_height' => $spec['height'] ?? null,
                ]);
            }

            $piItem = ProformaInvoiceItem::create([
                'proforma_invoice_id' => $pi->id,
                'product_id' => $product->id,
                'description' => $name,
                'quantity' => $spec['qty'],
                'unit_price' => 1000,
                'unit' => 'pcs',
            ]);

            $items[$name] = ShipmentItem::create([
                'shipment_id' => $shipment->id,
                'proforma_invoice_item_id' => $piItem->id,
                'quantity' => $spec['qty'],
                'sort_order' => count($items),
            ]);
        }

        return [$shipment, $items];
    }

    public function test_separate_mode_creates_one_carton_per_full_pack(): void
    {
        [$shipment] = $this->makeShipmentWithProducts([
            'sandals' => ['qty' => 100, 'pcs_per_carton' => 10, 'carton_weight' => 5.0],
        ]);

        $created = $this->generate->execute($shipment, false);

        $this->assertEquals(10, $created);
        $this->assertEquals(10, $shipment->cartons()->count());

        foreach ($shipment->cartons as $carton) {
            $this->assertEquals(1, $carton->contents->count());
            $this->assertEquals(10, $carton->contents->first()->pieces);
            $this->assertEquals('5.000', $carton->gross_weight);
        }
    }

    public function test_separate_mode_handles_remainder(): void
    {
        [$shipment] = $this->makeShipmentWithProducts([
            'widgets' => ['qty' => 95, 'pcs_per_carton' => 10, 'carton_weight' => 10.0],
        ]);

        $created = $this->generate->execute($shipment, false);

        $this->assertEquals(10, $created); // 9 full + 1 remainder
        $this->assertEquals(10, $shipment->cartons()->count());

        $totalPieces = $shipment->cartons()->with('contents')->get()
            ->flatMap(fn ($c) => $c->contents)
            ->sum('pieces');
        $this->assertEquals(95, $totalPieces);

        // Remainder carton should have scaled weight
        $cartons = $shipment->cartons()->with('contents')->get();
        $remainderCarton = $cartons->first(fn ($c) => $c->contents->first()->pieces === 5);
        $this->assertNotNull($remainderCarton);
        $this->assertEquals('5.000', $remainderCarton->gross_weight); // 10.0 * 0.5
    }

    public function test_separate_mode_without_packaging_uses_single_carton(): void
    {
        [$shipment] = $this->makeShipmentWithProducts([
            'unknown' => ['qty' => 42], // no packaging
        ]);

        $created = $this->generate->execute($shipment, false);

        $this->assertEquals(1, $created);
        $this->assertEquals(1, $shipment->cartons()->count());

        $carton = $shipment->cartons()->with('contents')->first();
        $this->assertEquals(42, $carton->contents->first()->pieces);
    }

    public function test_separate_mode_multiple_items_independent(): void
    {
        [$shipment] = $this->makeShipmentWithProducts([
            'sandals' => ['qty' => 20, 'pcs_per_carton' => 10, 'carton_weight' => 5.0],
            'socks' => ['qty' => 15, 'pcs_per_carton' => 5, 'carton_weight' => 2.0],
        ]);

        $created = $this->generate->execute($shipment, false);

        $this->assertEquals(5, $created); // 2 sandals + 3 socks
        $this->assertEquals(5, $shipment->cartons()->count());

        // Labels should be sequential
        $labels = $shipment->cartons()->orderBy('label')->pluck('label')->all();
        $this->assertEquals(['BOX-001', 'BOX-002', 'BOX-003', 'BOX-004', 'BOX-005'], $labels);
    }

    public function test_mixed_mode_shares_cartons(): void
    {
        [$shipment, $items] = $this->makeShipmentWithProducts([
            'sandals' => ['qty' => 10, 'pcs_per_carton' => 5],
            'socks' => ['qty' => 20, 'pcs_per_carton' => 5],
        ]);

        // sandals need 2 boxes, socks need 4 — maxBoxes=4
        $created = $this->generate->execute($shipment, true, ['gross_weight' => 8.0]);

        $this->assertEquals(4, $created);
        $this->assertEquals(4, $shipment->cartons()->count());

        // Each carton should carry 8.0 kg gross
        foreach ($shipment->cartons as $carton) {
            $this->assertEquals('8.000', $carton->gross_weight);
        }

        // Total pieces across contents should equal 30
        $totalPieces = $shipment->cartons()->with('contents')->get()
            ->flatMap(fn ($c) => $c->contents)
            ->sum('pieces');
        $this->assertEquals(30, $totalPieces);
    }

    public function test_mixed_mode_with_full_config(): void
    {
        [$shipment] = $this->makeShipmentWithProducts([
            'items' => ['qty' => 10, 'pcs_per_carton' => 10],
        ]);

        $this->generate->execute($shipment, true, [
            'gross_weight' => 15.0,
            'length' => 50,
            'width' => 40,
            'height' => 30,
        ]);

        $carton = $shipment->cartons()->first();
        $this->assertEquals('15.000', $carton->gross_weight);
        $this->assertEquals('13.500', $carton->net_weight); // 90% of gross
        $this->assertEquals('50.00', $carton->length);
        $this->assertEquals('0.0600', $carton->volume); // 50*40*30 / 1e6
    }

    public function test_running_twice_wipes_existing_cartons(): void
    {
        [$shipment] = $this->makeShipmentWithProducts([
            'sandals' => ['qty' => 20, 'pcs_per_carton' => 10, 'carton_weight' => 5.0],
        ]);

        $this->generate->execute($shipment, false);
        $this->assertEquals(2, $shipment->cartons()->count());

        $this->generate->execute($shipment, false);
        $shipment->refresh();
        $this->assertEquals(2, $shipment->cartons()->count(), 'Second run should replace, not duplicate');
    }

    public function test_recalc_dispatched_sets_shipment_totals(): void
    {
        [$shipment] = $this->makeShipmentWithProducts([
            'sandals' => ['qty' => 20, 'pcs_per_carton' => 10, 'carton_weight' => 5.0],
        ]);

        $this->generate->execute($shipment, false);

        $shipment->refresh();
        $this->assertEquals(2, $shipment->total_packages);
        $this->assertEquals('10.000', $shipment->total_gross_weight);
    }

    public function test_empty_shipment_returns_zero(): void
    {
        [$shipment] = $this->makeShipmentWithProducts([]);

        $created = $this->generate->execute($shipment, false);

        $this->assertEquals(0, $created);
        $this->assertEquals(0, $shipment->cartons()->count());
    }

    public function test_skips_items_with_zero_quantity(): void
    {
        [$shipment] = $this->makeShipmentWithProducts([
            'a' => ['qty' => 10, 'pcs_per_carton' => 10],
            'b' => ['qty' => 0, 'pcs_per_carton' => 10],
        ]);

        $created = $this->generate->execute($shipment, false);

        $this->assertEquals(1, $created); // Only item A
    }
}
