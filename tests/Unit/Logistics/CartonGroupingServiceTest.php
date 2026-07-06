<?php

namespace Tests\Unit\Logistics;

use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Models\CartonContent;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\Logistics\Services\CartonGroupingService;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class CartonGroupingServiceTest extends TestCase
{
    use RefreshDatabase;

    private CartonGroupingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CartonGroupingService::class);
    }

    private function makeShipmentWithItems(array $itemQuantities): array
    {
        $client = Company::create(['name' => 'Client CG-'.uniqid(), 'status' => 'active']);
        $client->companyRoles()->create(['role' => 'client']);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-CG-'.uniqid(),
            'company_id' => $client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $pi = ProformaInvoice::create([
            'reference' => 'PI-CG-'.uniqid(),
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-04-08',
            'status' => 'confirmed',
        ]);

        $shipment = Shipment::create([
            'reference' => 'SHIP-CG-'.uniqid(),
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'status' => 'draft',
            'transport_mode' => 'sea',
            'origin_port' => 'Shanghai',
            'destination_port' => 'Santos',
        ]);

        $items = [];
        foreach ($itemQuantities as $key => $qty) {
            $piItem = ProformaInvoiceItem::create([
                'proforma_invoice_id' => $pi->id,
                'description' => "Product {$key}",
                'quantity' => $qty,
                'unit_price' => 1000,
                'unit' => 'pcs',
            ]);

            $items[$key] = ShipmentItem::create([
                'shipment_id' => $shipment->id,
                'proforma_invoice_item_id' => $piItem->id,
                'quantity' => $qty,
                'sort_order' => count($items),
            ]);
        }

        return [$shipment, $items];
    }

    private function makeCarton(Shipment $shipment, string $label, int $sortOrder): Carton
    {
        return Carton::create([
            'shipment_id' => $shipment->id,
            'label' => $label,
            'packaging_type' => 'CARTON',
            'sort_order' => $sortOrder,
        ]);
    }

    private function addContent(Carton $carton, int $shipmentItemId, int $pieces, ?string $partLabel = null, ?string $setId = null): CartonContent
    {
        return CartonContent::create([
            'carton_id' => $carton->id,
            'shipment_item_id' => $shipmentItemId,
            'pieces' => $pieces,
            'multi_box_set_id' => $setId,
            'part_label' => $partLabel,
            'sort_order' => $carton->contents()->count() + 1,
        ]);
    }

    /**
     * Reload cartons with contents eager loaded — the service expects this.
     *
     * @param  array<Carton>  $cartons
     */
    private function reload(array $cartons): Collection
    {
        $ids = collect($cartons)->pluck('id');

        return Carton::query()->whereIn('id', $ids)->with('contents')->orderBy('sort_order')->get();
    }

    public function test_empty_collection_returns_empty(): void
    {
        $result = $this->service->groupByContent(collect());

        $this->assertTrue($result->isEmpty());
    }

    public function test_groups_cartons_with_identical_single_content(): void
    {
        [$shipment, $items] = $this->makeShipmentWithItems(['caneca' => 200]);

        $cartons = [];
        for ($i = 1; $i <= 4; $i++) {
            $c = $this->makeCarton($shipment, "BOX-{$i}", $i);
            $this->addContent($c, $items['caneca']->id, 50);
            $cartons[] = $c;
        }

        $groups = $this->service->groupByContent($this->reload($cartons));

        $this->assertCount(1, $groups);
        $this->assertEquals(4, $groups->first()->count());
    }

    public function test_separates_cartons_with_different_pieces_for_same_product(): void
    {
        [$shipment, $items] = $this->makeShipmentWithItems(['caneca' => 110]);

        $c1 = $this->makeCarton($shipment, 'BOX-1', 1);
        $this->addContent($c1, $items['caneca']->id, 50);

        $c2 = $this->makeCarton($shipment, 'BOX-2', 2);
        $this->addContent($c2, $items['caneca']->id, 50);

        $c3 = $this->makeCarton($shipment, 'BOX-3', 3);
        $this->addContent($c3, $items['caneca']->id, 10);

        $groups = $this->service->groupByContent($this->reload([$c1, $c2, $c3]));

        // Two subgroups: the 50-pcs pair and the 10-pcs single
        $this->assertCount(2, $groups);
        $sizes = $groups->map->count()->values()->all();
        $this->assertEqualsCanonicalizing([2, 1], $sizes);
    }

    public function test_groups_mixed_boxes_under_mixed_signature(): void
    {
        [$shipment, $items] = $this->makeShipmentWithItems([
            'caneca' => 100,
            'copo' => 100,
        ]);

        // 2 single-content cartons (Caneca)
        $singleA = $this->makeCarton($shipment, 'BOX-1', 1);
        $this->addContent($singleA, $items['caneca']->id, 50);
        $singleB = $this->makeCarton($shipment, 'BOX-2', 2);
        $this->addContent($singleB, $items['caneca']->id, 50);

        // 2 mixed cartons (Caneca + Copo)
        $mixedA = $this->makeCarton($shipment, 'BOX-3', 3);
        $this->addContent($mixedA, $items['caneca']->id, 5);
        $this->addContent($mixedA, $items['copo']->id, 5);

        $mixedB = $this->makeCarton($shipment, 'BOX-4', 4);
        $this->addContent($mixedB, $items['caneca']->id, 7);
        $this->addContent($mixedB, $items['copo']->id, 3);

        $groups = $this->service->groupByContent($this->reload([$singleA, $singleB, $mixedA, $mixedB]));

        $this->assertCount(2, $groups);
        $this->assertArrayHasKey(CartonGroupingService::SIGNATURE_MIXED, $groups->all());
        $this->assertEquals(2, $groups[CartonGroupingService::SIGNATURE_MIXED]->count());
    }

    public function test_split_part_labels_create_separate_groups(): void
    {
        [$shipment, $items] = $this->makeShipmentWithItems(['chair' => 10]);
        $setId = (string) \Illuminate\Support\Str::ulid();

        // 3 cartons with Frame
        $f1 = $this->makeCarton($shipment, 'BOX-1', 1);
        $this->addContent($f1, $items['chair']->id, 12, 'Frame', $setId);
        $f2 = $this->makeCarton($shipment, 'BOX-2', 2);
        $this->addContent($f2, $items['chair']->id, 12, 'Frame', $setId);
        $f3 = $this->makeCarton($shipment, 'BOX-3', 3);
        $this->addContent($f3, $items['chair']->id, 12, 'Frame', $setId);

        // 2 cartons with Accessories
        $a1 = $this->makeCarton($shipment, 'BOX-4', 4);
        $this->addContent($a1, $items['chair']->id, 12, 'Accessories', $setId);
        $a2 = $this->makeCarton($shipment, 'BOX-5', 5);
        $this->addContent($a2, $items['chair']->id, 12, 'Accessories', $setId);

        $groups = $this->service->groupByContent($this->reload([$f1, $f2, $f3, $a1, $a2]));

        $this->assertCount(2, $groups);
        $sizes = $groups->map->count()->values()->all();
        $this->assertEqualsCanonicalizing([3, 2], $sizes);
    }

    public function test_empty_cartons_form_their_own_group_not_mixed(): void
    {
        [$shipment, $items] = $this->makeShipmentWithItems([
            'caneca' => 100,
            'copo' => 100,
        ]);

        // A truly mixed box (two products)…
        $mixed = $this->makeCarton($shipment, 'BOX-1', 1);
        $this->addContent($mixed, $items['caneca']->id, 5);
        $this->addContent($mixed, $items['copo']->id, 5);

        // …and two empty boxes must NOT land in the same subgroup.
        $emptyA = $this->makeCarton($shipment, 'BOX-2', 2);
        $emptyB = $this->makeCarton($shipment, 'BOX-3', 3);

        $groups = $this->service->groupByContent($this->reload([$mixed, $emptyA, $emptyB]));

        $this->assertCount(2, $groups);
        $this->assertArrayHasKey(CartonGroupingService::SIGNATURE_MIXED, $groups->all());
        $this->assertArrayHasKey(CartonGroupingService::SIGNATURE_EMPTY, $groups->all());
        $this->assertEquals(1, $groups[CartonGroupingService::SIGNATURE_MIXED]->count());
        $this->assertEquals(2, $groups[CartonGroupingService::SIGNATURE_EMPTY]->count());
    }

    public function test_repeated_rows_of_same_product_are_summed_not_mixed(): void
    {
        [$shipment, $items] = $this->makeShipmentWithItems(['etiqueta' => 3000]);

        // Same product added in two operations (1000 + 500) — not a mixed box.
        $c1 = $this->makeCarton($shipment, 'BOX-1', 1);
        $this->addContent($c1, $items['etiqueta']->id, 1000);
        $this->addContent($c1, $items['etiqueta']->id, 500);

        // Single row with the same total → same subgroup as BOX-1.
        $c2 = $this->makeCarton($shipment, 'BOX-2', 2);
        $this->addContent($c2, $items['etiqueta']->id, 1500);

        // Different total → its own subgroup.
        $c3 = $this->makeCarton($shipment, 'BOX-3', 3);
        $this->addContent($c3, $items['etiqueta']->id, 300);

        $groups = $this->service->groupByContent($this->reload([$c1, $c2, $c3]));

        $this->assertCount(2, $groups);
        $this->assertArrayNotHasKey(CartonGroupingService::SIGNATURE_MIXED, $groups->all());
        $sizes = $groups->map->count()->values()->all();
        $this->assertEqualsCanonicalizing([2, 1], $sizes);
    }

    public function test_same_product_with_different_part_labels_is_still_mixed(): void
    {
        [$shipment, $items] = $this->makeShipmentWithItems(['chair' => 10]);
        $setId = (string) \Illuminate\Support\Str::ulid();

        $c = $this->makeCarton($shipment, 'BOX-1', 1);
        $this->addContent($c, $items['chair']->id, 2, 'Frame', $setId);
        $this->addContent($c, $items['chair']->id, 2, 'Accessories', $setId);

        $groups = $this->service->groupByContent($this->reload([$c]));

        $this->assertArrayHasKey(CartonGroupingService::SIGNATURE_MIXED, $groups->all());
    }

    public function test_preserves_sort_order_across_subgroups(): void
    {
        [$shipment, $items] = $this->makeShipmentWithItems([
            'caneca' => 100,
            'copo' => 100,
        ]);

        // Interleaved sort order: Caneca first (sort 1, 3), Copo later (sort 2, 4)
        $c1 = $this->makeCarton($shipment, 'BOX-A', 1);
        $this->addContent($c1, $items['caneca']->id, 50);

        $c2 = $this->makeCarton($shipment, 'BOX-B', 2);
        $this->addContent($c2, $items['copo']->id, 50);

        $c3 = $this->makeCarton($shipment, 'BOX-C', 3);
        $this->addContent($c3, $items['caneca']->id, 50);

        $c4 = $this->makeCarton($shipment, 'BOX-D', 4);
        $this->addContent($c4, $items['copo']->id, 50);

        $groups = $this->service->groupByContent($this->reload([$c1, $c2, $c3, $c4]));

        // Caneca subgroup (min sort_order=1) comes before Copo subgroup (min sort_order=2)
        $orderedFirstIds = $groups->map(fn ($g) => $g->first()->id)->values()->all();
        $this->assertSame([$c1->id, $c2->id], $orderedFirstIds);
    }
}
