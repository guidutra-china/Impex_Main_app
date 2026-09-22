<?php

namespace Tests\Feature\Logistics;

use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Models\CartonContent;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentContainer;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\Logistics\Services\PackingProgressService;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SplitSmallPartsCommandTest extends TestCase
{
    use RefreshDatabase;

    private Shipment $shipment;

    private ShipmentItem $item;

    private ShipmentContainer $container;

    /**
     * Miniatura do SH-2026-00037: 4 esteiras, cada uma sozinha na sua caixa,
     * todas no mesmo container, sem split.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $client = Company::create(['name' => 'Client SSP-'.uniqid(), 'status' => 'active']);
        $client->companyRoles()->create(['role' => 'client']);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-SSP-'.uniqid(),
            'company_id' => $client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $pi = ProformaInvoice::create([
            'reference' => 'PI-SSP-'.uniqid(),
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-09-01',
            'status' => 'confirmed',
        ]);

        $piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description' => 'TA358HT Motorized Treadmill',
            'quantity' => 4,
            'unit_price' => 1000,
            'unit' => 'pcs',
        ]);

        $this->shipment = Shipment::create([
            'reference' => 'SH-SSP-0001',
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'status' => 'in_transit',
            'transport_mode' => 'sea',
            'origin_port' => 'Shanghai',
            'destination_port' => 'Santos',
        ]);

        $this->item = ShipmentItem::create([
            'shipment_id' => $this->shipment->id,
            'proforma_invoice_item_id' => $piItem->id,
            'quantity' => 4,
            'sort_order' => 0,
        ]);

        $this->container = ShipmentContainer::create([
            'shipment_id' => $this->shipment->id,
            'label' => 'CONT-001',
        ]);

        for ($i = 1; $i <= 4; $i++) {
            $carton = Carton::create([
                'shipment_id' => $this->shipment->id,
                'shipment_container_id' => $this->container->id,
                'label' => 'BOX-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'packaging_type' => 'carton',
                'gross_weight' => 164.0,
                'net_weight' => 147.0,
                'volume' => 0.892424,
                'sort_order' => $i,
            ]);

            CartonContent::create([
                'carton_id' => $carton->id,
                'shipment_item_id' => $this->item->id,
                'pieces' => 1,
                'sort_order' => 1,
            ]);
        }
    }

    private function split(array $options = []): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('shipments:split-small-parts', array_merge([
            'reference' => 'SH-SSP-0001',
            '--main-label' => 'Treadmill',
            '--boxes' => 2,
            '--gross' => 13.6,
            '--volume' => 0.098064,
        ], $options));
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->split()->assertExitCode(0);

        $this->assertSame(4, $this->shipment->cartons()->count());
        $this->assertNull($this->item->fresh()->packing_split);
        $this->assertSame(0, CartonContent::whereNotNull('part_label')->count());
    }

    public function test_apply_splits_the_item_and_adds_the_small_parts_boxes(): void
    {
        $this->split(['--apply' => true])->assertExitCode(0);

        $item = $this->item->fresh();
        $this->assertSame(['Treadmill', 'Small Parts'], $item->packing_split['part_labels']);
        $setId = $item->packing_split['set_id'];

        // As 4 caixas de esteira viram a parte "Treadmill" do split.
        $this->assertSame(4, CartonContent::where('multi_box_set_id', $setId)->where('part_label', 'Treadmill')->count());

        // 2 caixas novas, no mesmo container, com os pesos pedidos e as 4 peças repartidas.
        $new = $this->shipment->cartons()->whereIn('label', ['BOX-005', 'BOX-006'])->with('contents')->get();
        $this->assertCount(2, $new);
        foreach ($new as $carton) {
            $this->assertSame($this->container->id, $carton->shipment_container_id);
            $this->assertEquals('13.600', $carton->gross_weight);
            $this->assertEquals('12.240', $carton->net_weight); // 90% do GW, como o builder
            $this->assertEqualsWithDelta(0.098064, (float) $carton->volume, 0.000001);
            $this->assertSame('Small Parts', $carton->contents->sole()->part_label);
            $this->assertSame(2, $carton->contents->sole()->pieces);
        }

        // O item fecha completo nas duas partes.
        $progress = app(PackingProgressService::class)->forShipmentItem($item);
        $this->assertSame(4, $progress->packedComplete);

        // Totais do embarque: 6 volumes, 4×164 + 2×13,6 kg.
        $shipment = $this->shipment->fresh();
        $this->assertSame(6, $shipment->total_packages);
        $this->assertEquals('683.200', $shipment->total_gross_weight);
    }

    public function test_uneven_split_puts_the_remainder_in_the_first_boxes(): void
    {
        $this->split(['--apply' => true, '--boxes' => 3])->assertExitCode(0);

        $pieces = CartonContent::where('part_label', 'Small Parts')
            ->join('cartons', 'cartons.id', '=', 'carton_contents.carton_id')
            ->orderBy('cartons.label')
            ->pluck('pieces')
            ->all();

        $this->assertSame([2, 1, 1], $pieces);
    }

    public function test_running_twice_is_a_no_op(): void
    {
        $this->split(['--apply' => true])->assertExitCode(0);
        $this->split(['--apply' => true])
            ->expectsOutputToContain('já aplicado')
            ->assertExitCode(0);

        $this->assertSame(6, $this->shipment->cartons()->count());
    }

    public function test_refuses_when_the_item_is_not_fully_packed(): void
    {
        CartonContent::where('shipment_item_id', $this->item->id)->first()->delete();

        $this->split(['--apply' => true])->assertExitCode(1);

        $this->assertNull($this->item->fresh()->packing_split);
        $this->assertSame(4, $this->shipment->cartons()->count());
    }
}
