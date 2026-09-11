<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillProductCartonWeightsCommandTest extends TestCase
{
    use RefreshDatabase;

    private Shipment $shipment;

    private ProformaInvoice $pi;

    protected function setUp(): void
    {
        parent::setUp();

        $client = Company::create(['name' => 'Client CW-'.uniqid(), 'status' => 'active']);
        $client->companyRoles()->create(['role' => 'client']);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-CW-'.uniqid(), 'company_id' => $client->id,
            'status' => 'received', 'source' => 'email', 'currency_code' => 'USD',
        ]);

        $this->pi = ProformaInvoice::create([
            'reference' => 'PI-CW-'.uniqid(), 'inquiry_id' => $inquiry->id, 'company_id' => $client->id,
            'currency_code' => 'USD', 'issue_date' => '2026-09-01', 'status' => 'confirmed',
        ]);

        $this->shipment = Shipment::create([
            'reference' => 'SH-CW-'.uniqid(), 'company_id' => $client->id, 'currency_code' => 'USD',
            'status' => 'in_transit', 'transport_mode' => 'sea', 'destination_port' => 'Paranagua',
        ]);
    }

    private function ship(Product $product): void
    {
        $piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $this->pi->id, 'product_id' => $product->id,
            'description' => $product->name, 'quantity' => 1, 'unit_price' => 100000, 'unit' => 'pcs',
        ]);

        ShipmentItem::create([
            'shipment_id' => $this->shipment->id, 'proforma_invoice_item_id' => $piItem->id,
            'quantity' => 1, 'sort_order' => 0,
        ]);
    }

    private function backfill(string $extra = ''): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan("products:backfill-carton-weights --shipment={$this->shipment->reference} {$extra}");
    }

    public function test_fills_the_carton_net_weight_from_the_unit_net_weight_times_pieces_per_carton(): void
    {
        $product = Product::factory()->create(['name' => 'Chest Press', 'reference_code' => 'CW-1']);
        $product->specification()->create(['net_weight' => 153.0]);
        $product->packaging()->create(['pcs_per_carton' => 2, 'carton_weight' => 320.0]);
        $this->ship($product);

        $this->backfill('--apply')->assertSuccessful();

        $packaging = $product->refresh()->packaging;
        $this->assertEquals(306.0, $packaging->carton_net_weight);
        $this->assertEquals(320.0, $packaging->carton_weight, 'bruto existente fica como está');
    }

    public function test_creates_the_packaging_row_when_the_product_has_none(): void
    {
        $product = Product::factory()->create(['name' => 'Dumbbell Rack', 'reference_code' => 'CW-2']);
        $product->specification()->create(['net_weight' => 45.0]);
        $this->ship($product);

        $this->backfill('--apply')->assertSuccessful();

        $packaging = $product->refresh()->packaging;
        $this->assertNotNull($packaging);
        $this->assertSame(1, $packaging->pcs_per_carton);
        $this->assertEquals(45.0, $packaging->carton_net_weight);
        $this->assertNull($packaging->carton_weight, 'rack não tem kg no nome: bruto fica em aberto');
    }

    public function test_a_product_whose_name_carries_its_nominal_kg_gets_gross_equal_to_net(): void
    {
        $plate = Product::factory()->create(['name' => 'Weight plate 2.5kg', 'reference_code' => 'CW-3']);
        $plate->specification()->create(['net_weight' => 2.5]);
        $this->ship($plate);

        $dumbbell = Product::factory()->create(['name' => 'Halter 12,5 kg', 'reference_code' => 'CW-4']);
        $dumbbell->specification()->create(['net_weight' => 12.5]);
        $this->ship($dumbbell);

        $this->backfill('--apply')->assertSuccessful();

        $this->assertEquals(2.5, $plate->refresh()->packaging->carton_net_weight);
        $this->assertEquals(2.5, $plate->packaging->carton_weight);
        $this->assertEquals(12.5, $dumbbell->refresh()->packaging->carton_net_weight);
        $this->assertEquals(12.5, $dumbbell->packaging->carton_weight);
    }

    public function test_a_kg_in_the_name_that_does_not_match_the_net_weight_does_not_set_gross(): void
    {
        // "1.5m bar" não é peso; e um nome com kg diferente do líquido cadastrado é suspeito.
        $product = Product::factory()->create(['name' => 'Kettlebell 16kg', 'reference_code' => 'CW-5']);
        $product->specification()->create(['net_weight' => 15.0]);
        $this->ship($product);

        $this->backfill('--apply')->assertSuccessful();

        $this->assertEquals(15.0, $product->refresh()->packaging->carton_net_weight);
        $this->assertNull($product->packaging->carton_weight);
    }

    public function test_never_overwrites_an_existing_carton_net_weight(): void
    {
        $product = Product::factory()->create(['name' => 'Weight plate 5kg', 'reference_code' => 'CW-6']);
        $product->specification()->create(['net_weight' => 5.0]);
        $product->packaging()->create(['pcs_per_carton' => 1, 'carton_net_weight' => 4.8, 'carton_weight' => 5.2]);
        $this->ship($product);

        $this->backfill('--apply')->assertSuccessful();

        $this->assertEquals(4.8, $product->refresh()->packaging->carton_net_weight);
        $this->assertEquals(5.2, $product->packaging->carton_weight);
    }

    public function test_skips_a_product_without_unit_net_weight_and_says_so(): void
    {
        $product = Product::factory()->create(['name' => 'Mystery', 'reference_code' => 'CW-7']);
        $this->ship($product);

        $this->backfill('--apply')->expectsOutputToContain('sem líquido unitário')->assertSuccessful();

        $this->assertNull($product->refresh()->packaging);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $product = Product::factory()->create(['name' => 'Weight plate 10kg', 'reference_code' => 'CW-8']);
        $product->specification()->create(['net_weight' => 10.0]);
        $this->ship($product);

        $this->backfill()->expectsOutputToContain('Dry-run')->assertSuccessful();

        $this->assertNull($product->refresh()->packaging);
    }
}
