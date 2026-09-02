<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Models\CartonContent;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackfillProductNetWeightCommandTest extends TestCase
{
    use RefreshDatabase;

    private Shipment $shipment;

    private ProformaInvoice $pi;

    protected function setUp(): void
    {
        parent::setUp();

        $client = Company::create(['name' => 'Client NW-'.uniqid(), 'status' => 'active']);
        $client->companyRoles()->create(['role' => 'client']);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-NW-'.uniqid(), 'company_id' => $client->id,
            'status' => 'received', 'source' => 'email', 'currency_code' => 'USD',
        ]);

        $this->pi = ProformaInvoice::create([
            'reference' => 'PI-NW-'.uniqid(), 'inquiry_id' => $inquiry->id, 'company_id' => $client->id,
            'currency_code' => 'USD', 'issue_date' => '2026-09-01', 'status' => 'confirmed',
        ]);

        $this->shipment = Shipment::create([
            'reference' => 'SH-NW-'.uniqid(), 'company_id' => $client->id, 'currency_code' => 'USD',
            'status' => 'in_transit', 'transport_mode' => 'sea', 'destination_port' => 'Paranagua',
        ]);
    }

    private function item(Product $product, int $quantity, ?array $parts = null): ShipmentItem
    {
        $piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $this->pi->id, 'product_id' => $product->id,
            'description' => $product->name, 'quantity' => $quantity, 'unit_price' => 100000, 'unit' => 'pcs',
        ]);

        return ShipmentItem::create([
            'shipment_id' => $this->shipment->id, 'proforma_invoice_item_id' => $piItem->id,
            'quantity' => $quantity, 'sort_order' => 0,
            'packing_split' => $parts ? ['set_id' => (string) Str::ulid(), 'part_labels' => $parts] : null,
        ]);
    }

    private function carton(float $gross, ?float $net, array $contents): Carton
    {
        $carton = Carton::create([
            'shipment_id' => $this->shipment->id,
            'label' => 'BOX-'.str_pad((string) (Carton::where('shipment_id', $this->shipment->id)->count() + 1), 3, '0', STR_PAD_LEFT),
            'packaging_type' => 'carton', 'gross_weight' => $gross, 'net_weight' => $net,
            'sort_order' => Carton::where('shipment_id', $this->shipment->id)->count() + 1,
        ]);

        foreach ($contents as $i => [$item, $pieces]) {
            CartonContent::create([
                'carton_id' => $carton->id, 'shipment_item_id' => $item->id,
                'pieces' => $pieces, 'sort_order' => $i + 1,
            ]);
        }

        return $carton;
    }

    public function test_derives_the_unit_net_weight_from_a_volume_that_carries_one_product_only(): void
    {
        $product = Product::factory()->create(['reference_code' => 'SOLO-1']);
        $item = $this->item($product, 4);

        $this->carton(120.0, 100.0, [[$item, 2]]);
        $this->carton(120.0, 100.0, [[$item, 2]]);

        $this->artisan("products:backfill-net-weight --shipment={$this->shipment->reference} --apply")->assertSuccessful();

        $this->assertEquals(50.0, $product->refresh()->specification->net_weight);
    }

    public function test_a_multi_box_piece_sums_its_parts(): void
    {
        $product = Product::factory()->create(['reference_code' => 'MB-1']);
        $item = $this->item($product, 2, ['Part 1', 'Part 2']);

        // Quatro caixas de 100 kg líquidos: duas peças de 200 kg cada.
        for ($i = 0; $i < 4; $i++) {
            $this->carton(125.0, 100.0, [[$item, 1]]);
        }

        $this->artisan("products:backfill-net-weight --shipment={$this->shipment->reference} --apply")->assertSuccessful();

        $this->assertEquals(200.0, $product->refresh()->specification->net_weight);
    }

    public function test_falls_back_to_ninety_percent_of_gross_when_the_volume_has_no_net_weight(): void
    {
        $product = Product::factory()->create(['reference_code' => 'GROSS-1']);
        $item = $this->item($product, 2);

        $this->carton(200.0, null, [[$item, 2]]);

        $this->artisan("products:backfill-net-weight --shipment={$this->shipment->reference} --apply")->assertSuccessful();

        $this->assertEquals(90.0, $product->refresh()->specification->net_weight);
    }

    public function test_leaves_a_product_that_only_travels_in_a_shared_volume_without_a_base(): void
    {
        $a = Product::factory()->create(['reference_code' => 'MIX-A']);
        $b = Product::factory()->create(['reference_code' => 'MIX-B']);

        $this->carton(300.0, 280.0, [[$this->item($a, 1), 1], [$this->item($b, 1), 1]]);

        $this->artisan("products:backfill-net-weight --shipment={$this->shipment->reference} --apply")
            ->expectsOutputToContain('sem base')
            ->assertSuccessful();

        $this->assertNull($a->refresh()->specification);
        $this->assertNull($b->refresh()->specification);
    }

    public function test_does_not_touch_a_product_that_already_has_a_net_weight(): void
    {
        $product = Product::factory()->create(['reference_code' => 'KEEP-1']);
        $product->specification()->create(['net_weight' => 12.345]);
        $item = $this->item($product, 1);
        $this->carton(120.0, 100.0, [[$item, 1]]);

        $this->artisan("products:backfill-net-weight --shipment={$this->shipment->reference} --apply")->assertSuccessful();
        $this->assertEquals(12.345, $product->refresh()->specification->net_weight);

        $this->artisan("products:backfill-net-weight --shipment={$this->shipment->reference} --overwrite --apply")->assertSuccessful();
        $this->assertEquals(100.0, $product->refresh()->specification->net_weight);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $product = Product::factory()->create(['reference_code' => 'DRY-1']);
        $this->carton(120.0, 100.0, [[$this->item($product, 1), 1]]);

        $this->artisan("products:backfill-net-weight --shipment={$this->shipment->reference}")->assertSuccessful();

        $this->assertNull($product->refresh()->specification);
    }
}
