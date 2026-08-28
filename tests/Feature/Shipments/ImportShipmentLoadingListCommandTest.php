<?php

namespace Tests\Feature\Shipments;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Models\CartonContent;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentContainer;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ImportShipmentLoadingListCommandTest extends TestCase
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

    /**
     * Cria um embarque com um item por modelo. O valor de cada entrada é a
     * quantidade em PEÇAS; passe um array ['qty' => n, 'parts' => [...]] para
     * um item dividido em multi-box.
     */
    private function makeShipment(array $models): array
    {
        $client = Company::create(['name' => 'Client LL-'.uniqid(), 'status' => 'active']);
        $client->companyRoles()->create(['role' => 'client']);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-LL-'.uniqid(),
            'company_id' => $client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $pi = ProformaInvoice::create([
            'reference' => 'PI-LL-'.uniqid(),
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-08-01',
            'status' => 'confirmed',
        ]);

        $shipment = Shipment::create([
            'reference' => 'SH-LL-'.uniqid(),
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'status' => 'in_transit',
            'transport_mode' => 'sea',
            'destination_port' => 'Paranagua',
        ]);

        $items = [];

        foreach ($models as $code => $spec) {
            $spec = is_array($spec) ? $spec : ['qty' => $spec];

            $product = Product::factory()->create(['reference_code' => $code]);

            $piItem = ProformaInvoiceItem::create([
                'proforma_invoice_id' => $pi->id,
                'product_id' => $product->id,
                'description' => "Product {$code}",
                'quantity' => $spec['qty'],
                'unit_price' => 100000,
                'unit' => 'pcs',
            ]);

            $items[$code] = ShipmentItem::create([
                'shipment_id' => $shipment->id,
                'proforma_invoice_item_id' => $piItem->id,
                'quantity' => $spec['qty'],
                'sort_order' => count($items),
                'packing_split' => isset($spec['parts'])
                    ? ['set_id' => (string) Str::ulid(), 'part_labels' => $spec['parts']]
                    : null,
            ]);
        }

        return [$shipment, $items];
    }

    /**
     * Escreve um JSON de loading list. $containers é uma lista de
     * [numero, [modelo => volumes, ...]] — pesos unitários são fixos e
     * declarados por dedução, como na planilha real.
     */
    private function makeFile(Shipment $shipment, array $containers): string
    {
        $payload = ['shipment' => $shipment->reference, 'containers' => []];

        foreach ($containers as $index => [$number, $lines]) {
            $entries = [];
            $packages = 0;

            foreach ($lines as $model => $count) {
                $entries[] = [
                    'model' => $model,
                    'description' => "Product {$model}",
                    'packages' => $count,
                    'unit_net_weight' => 10,
                    'unit_gross_weight' => 12,
                    'unit_volume' => 0.5,
                    'notes' => null,
                ];
                $packages += $count;
            }

            $payload['containers'][] = [
                'label' => 'CONT-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                'container_number' => $number,
                'type' => '40HQ',
                'sort_order' => $index + 1,
                'declared' => [
                    'packages' => $packages,
                    'net_weight' => $packages * 10,
                    'gross_weight' => $packages * 12,
                    'volume' => $packages * 0.5,
                ],
                'lines' => $entries,
            ];
        }

        $this->file = tempnam(sys_get_temp_dir(), 'll').'.json';
        file_put_contents($this->file, json_encode($payload));

        return $this->file;
    }

    public function test_creates_containers_cartons_and_recalculates_shipment_totals(): void
    {
        [$shipment, $items] = $this->makeShipment(['D905Z' => 3, 'U3050' => 2]);
        $file = $this->makeFile($shipment, [
            ['HMMU1111111', ['D905Z' => 2]],
            ['KOCU2222222', ['D905Z' => 1, 'U3050' => 2]],
        ]);

        $this->artisan("shipments:import-loading-list {$file} --apply")->assertSuccessful();

        $this->assertSame(2, ShipmentContainer::where('shipment_id', $shipment->id)->count());
        $this->assertSame(5, Carton::where('shipment_id', $shipment->id)->count());

        $first = Carton::where('shipment_id', $shipment->id)->orderBy('sort_order')->first();
        $this->assertSame('BOX-001', $first->label);
        $this->assertEquals(12, $first->gross_weight);
        $this->assertEquals(10, $first->net_weight);
        $this->assertEquals(0.5, $first->volume);
        $this->assertSame(
            ShipmentContainer::where('container_number', 'HMMU1111111')->value('id'),
            $first->shipment_container_id,
        );

        $shipment->refresh();
        $this->assertSame(5, (int) $shipment->total_packages);
        $this->assertEquals(60, $shipment->total_gross_weight);
        $this->assertEquals(50, $shipment->total_net_weight);
        $this->assertEquals(2.5, $shipment->total_volume);
        $this->assertSame('HMMU1111111, KOCU2222222', $shipment->container_number);

        // Peso e cubagem do item passam a refletir o loading list.
        $items['D905Z']->refresh();
        $this->assertEquals(12, $items['D905Z']->unit_weight);
        $this->assertEquals(36, $items['D905Z']->total_weight);
        $this->assertEquals(1.5, $items['D905Z']->total_volume);
    }

    public function test_aborts_without_writing_when_the_load_does_not_match_the_shipment_items(): void
    {
        [$shipment] = $this->makeShipment(['D905Z' => 3]);
        $file = $this->makeFile($shipment, [['HMMU1111111', ['D905Z' => 4]]]);

        $this->artisan("shipments:import-loading-list {$file} --apply")->assertFailed();

        $this->assertSame(0, Carton::where('shipment_id', $shipment->id)->count());
        $this->assertSame(0, ShipmentContainer::where('shipment_id', $shipment->id)->count());
    }

    public function test_aborts_when_a_model_has_no_matching_shipment_item(): void
    {
        [$shipment] = $this->makeShipment(['D905Z' => 1]);
        $file = $this->makeFile($shipment, [['HMMU1111111', ['D905Z' => 1, 'X9999' => 1]]]);

        $this->artisan("shipments:import-loading-list {$file} --apply")->assertFailed();

        $this->assertSame(0, Carton::where('shipment_id', $shipment->id)->count());
    }

    public function test_splits_multi_box_items_across_their_parts(): void
    {
        [$shipment, $items] = $this->makeShipment([
            'U3016' => ['qty' => 8, 'parts' => ['Body', 'Parts']],
        ]);
        // 16 volumes = 8 peças × 2 partes, distribuídos 5 / 11 entre contêineres.
        $file = $this->makeFile($shipment, [
            ['HMMU1111111', ['U3016' => 5]],
            ['KOCU2222222', ['U3016' => 11]],
        ]);

        $this->artisan("shipments:import-loading-list {$file} --apply")->assertSuccessful();

        $contents = CartonContent::whereIn('carton_id', Carton::where('shipment_id', $shipment->id)->pluck('id'))->get();

        $this->assertCount(16, $contents);
        $this->assertSame(8, $contents->where('part_label', 'Body')->count());
        $this->assertSame(8, $contents->where('part_label', 'Parts')->count());
        $this->assertSame(
            [$items['U3016']->packing_split['set_id']],
            $contents->pluck('multi_box_set_id')->unique()->values()->all(),
        );

        // As partes se intercalam dentro do contêiner, em vez de todas as
        // "Body" caírem no primeiro e todas as "Parts" no segundo.
        $firstContainer = ShipmentContainer::where('container_number', 'HMMU1111111')->value('id');
        $inFirst = $contents->whereIn('carton_id', Carton::where('shipment_container_id', $firstContainer)->pluck('id'));
        $this->assertGreaterThan(0, $inFirst->where('part_label', 'Body')->count());
        $this->assertGreaterThan(0, $inFirst->where('part_label', 'Parts')->count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        [$shipment] = $this->makeShipment(['D905Z' => 2]);
        $file = $this->makeFile($shipment, [['HMMU1111111', ['D905Z' => 2]]]);

        $this->artisan("shipments:import-loading-list {$file}")->assertSuccessful();

        $this->assertSame(0, Carton::where('shipment_id', $shipment->id)->count());
        $this->assertSame(0, ShipmentContainer::where('shipment_id', $shipment->id)->count());
    }

    public function test_replaces_existing_cartons_but_expect_cartons_guards_an_unexpected_state(): void
    {
        [$shipment, $items] = $this->makeShipment(['D905Z' => 2]);

        $stale = Carton::create([
            'shipment_id' => $shipment->id,
            'label' => 'BOX-001',
            'packaging_type' => 'carton',
            'gross_weight' => 999,
            'sort_order' => 1,
        ]);
        CartonContent::create([
            'carton_id' => $stale->id,
            'shipment_item_id' => $items['D905Z']->id,
            'pieces' => 2,
            'sort_order' => 1,
        ]);

        $file = $this->makeFile($shipment, [['HMMU1111111', ['D905Z' => 2]]]);

        $this->artisan("shipments:import-loading-list {$file} --expect-cartons=5 --apply")->assertFailed();
        $this->assertSame(1, Carton::where('shipment_id', $shipment->id)->count());

        $this->artisan("shipments:import-loading-list {$file} --expect-cartons=1 --apply")->assertSuccessful();

        $cartons = Carton::where('shipment_id', $shipment->id)->get();
        $this->assertCount(2, $cartons);
        $this->assertEmpty($cartons->where('gross_weight', 999));
        $this->assertSame(0, CartonContent::where('carton_id', $stale->id)->count());
    }

    public function test_renumbers_containers_that_already_exist_under_another_label(): void
    {
        [$shipment] = $this->makeShipment(['D905Z' => 2]);

        // Estado anterior: o contêiner que o arquivo chama de CONT-002 já está
        // no banco como CONT-001. A renumeração não pode colidir com o rótulo
        // que o outro contêiner vai assumir.
        ShipmentContainer::create([
            'shipment_id' => $shipment->id,
            'label' => 'CONT-001',
            'container_number' => 'KOCU2222222',
            'type' => '40HQ',
            'sort_order' => 1,
        ]);

        $file = $this->makeFile($shipment, [
            ['HMMU1111111', ['D905Z' => 1]],
            ['KOCU2222222', ['D905Z' => 1]],
        ]);

        $this->artisan("shipments:import-loading-list {$file} --apply")->assertSuccessful();

        $containers = ShipmentContainer::where('shipment_id', $shipment->id)
            ->orderBy('sort_order')
            ->pluck('container_number', 'label')
            ->all();

        $this->assertSame(['CONT-001' => 'HMMU1111111', 'CONT-002' => 'KOCU2222222'], $containers);
    }

    public function test_drops_leftover_containers_that_are_not_in_the_file(): void
    {
        [$shipment] = $this->makeShipment(['D905Z' => 1]);

        ShipmentContainer::create([
            'shipment_id' => $shipment->id,
            'label' => 'CONT-009',
            'container_number' => 'OLDU9999999',
            'type' => '40HQ',
            'sort_order' => 9,
        ]);

        $file = $this->makeFile($shipment, [['HMMU1111111', ['D905Z' => 1]]]);

        $this->artisan("shipments:import-loading-list {$file} --apply")->assertSuccessful();

        $this->assertSame(
            ['HMMU1111111'],
            ShipmentContainer::where('shipment_id', $shipment->id)->pluck('container_number')->all(),
        );
    }

    public function test_keep_existing_appends_instead_of_replacing(): void
    {
        [$shipment, $items] = $this->makeShipment(['D905Z' => 2]);

        Carton::create([
            'shipment_id' => $shipment->id,
            'label' => 'BOX-001',
            'packaging_type' => 'carton',
            'sort_order' => 1,
        ]);

        $file = $this->makeFile($shipment, [['HMMU1111111', ['D905Z' => 2]]]);

        $this->artisan("shipments:import-loading-list {$file} --keep-existing --apply")->assertSuccessful();

        $labels = Carton::where('shipment_id', $shipment->id)->orderBy('sort_order')->pluck('label')->all();
        $this->assertSame(['BOX-001', 'BOX-002', 'BOX-003'], $labels);
    }
}
