<?php

namespace Tests\Feature\Logistics;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Pdf\Templates\PackingListPdfTemplate;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Models\CartonContent;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentContainer;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\Logistics\Models\ShipmentPallet;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

class PackingListPdfV2Test extends TestCase
{
    use RefreshDatabase;

    private function makeShipmentWithItems(array $itemQuantities): array
    {
        $client = Company::create(['name' => 'Client PL-'.uniqid(), 'status' => 'active']);
        $client->companyRoles()->create(['role' => 'client']);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-PL-'.uniqid(),
            'company_id' => $client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $pi = ProformaInvoice::create([
            'reference' => 'PI-PL-'.uniqid(),
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-04-08',
            'status' => 'confirmed',
        ]);

        $shipment = Shipment::create([
            'reference' => 'SHIP-PL-'.uniqid(),
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'status' => 'draft',
            'transport_mode' => 'sea',
            'origin_port' => 'Shanghai',
            'destination_port' => 'Santos',
            'issue_date' => '2026-04-08',
        ]);

        $items = [];
        foreach ($itemQuantities as $name => $qty) {
            $piItem = ProformaInvoiceItem::create([
                'proforma_invoice_id' => $pi->id,
                'description' => $name,
                'quantity' => $qty,
                'unit_price' => 1000,
                'unit' => 'pcs',
            ]);

            $items[$name] = ShipmentItem::create([
                'shipment_id' => $shipment->id,
                'proforma_invoice_item_id' => $piItem->id,
                'quantity' => $qty,
                'unit' => 'pcs',
                'sort_order' => count($items),
            ]);
        }

        return [$shipment, $items];
    }

    private function makeCarton(Shipment $shipment, string $label, array $attrs = []): Carton
    {
        return Carton::create(array_merge([
            'shipment_id' => $shipment->id,
            'label' => $label,
            'packaging_type' => 'CARTON',
            'sort_order' => $shipment->cartons()->count() + 1,
        ], $attrs));
    }

    private function addContent(Carton $carton, ?int $shipmentItemId, int $pieces, ?string $setId = null, ?string $partLabel = null): CartonContent
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

    private function getData(Shipment $shipment): array
    {
        $template = new PackingListPdfTemplate($shipment);
        $method = new ReflectionMethod($template, 'getDocumentData');
        $method->setAccessible(true);

        return $method->invoke($template);
    }

    public function test_scenario_1_normal_10_cartons_of_sandals(): void
    {
        [$shipment, $items] = $this->makeShipmentWithItems(['sandals' => 100]);

        for ($i = 1; $i <= 10; $i++) {
            $box = $this->makeCarton($shipment, 'BOX-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), [
                'gross_weight' => 5.0,
                'net_weight' => 4.5,
                'volume' => 0.020,
            ]);
            $this->addContent($box, $items['sandals']->id, 10);
        }

        $data = $this->getData($shipment);

        $this->assertCount(1, $data['container_groups']);
        // 10 identical cartons are grouped into 1 consolidated line
        $this->assertCount(1, $data['container_groups'][0]['lines']);
        $this->assertEquals(10, $data['totals']['total_packages']);
        $this->assertEquals(100, $data['totals']['total_equipment_qty']);
        $this->assertEqualsWithDelta(50.0, $data['totals']['total_gross_weight'], 0.01);

        // The single grouped line should show the range and totals
        $line = $data['container_groups'][0]['lines'][0];
        $this->assertFalse($line['is_sub_item']);
        $this->assertEquals(100, $line['equipment_qty']);
        $this->assertEquals(10, $line['package_qty']);
        $this->assertEquals('BOX-001 ~ BOX-010', $line['package_no']);
    }

    public function test_scenario_2_overflow_mixed_carton(): void
    {
        [$shipment, $items] = $this->makeShipmentWithItems([
            'sandals' => 95,
            'socks' => 3,
        ]);

        // 9 full cartons of sandals
        for ($i = 1; $i <= 9; $i++) {
            $box = $this->makeCarton($shipment, 'BOX-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), [
                'gross_weight' => 5.0,
            ]);
            $this->addContent($box, $items['sandals']->id, 10);
        }

        // 1 mixed carton: 5 sandals + 3 socks
        $mixed = $this->makeCarton($shipment, 'BOX-010', ['gross_weight' => 3.0]);
        $this->addContent($mixed, $items['sandals']->id, 5);
        $this->addContent($mixed, $items['socks']->id, 3);

        $data = $this->getData($shipment);

        $this->assertEquals(10, $data['totals']['total_packages']);
        $this->assertEquals(98, $data['totals']['total_equipment_qty']); // 95 sandals + 3 socks

        // 9 identical sandal cartons grouped into 1 line + 1 mixed carton (1 main + 1 sub) = 3 lines
        $this->assertCount(3, $data['container_groups'][0]['lines']);

        // Find the sub-item (socks in the mixed carton)
        $subItems = array_filter($data['container_groups'][0]['lines'], fn ($l) => $l['is_sub_item']);
        $this->assertCount(1, $subItems);
    }

    public function test_scenario_3_multi_box_product(): void
    {
        [$shipment, $items] = $this->makeShipmentWithItems(['machine' => 1]);

        $setId = (string) Str::ulid();
        $items['machine']->update([
            'packing_split' => ['set_id' => $setId, 'part_labels' => ['Frame', 'Accessories']],
        ]);

        $box1 = $this->makeCarton($shipment, 'BOX-001', ['gross_weight' => 50.0]);
        $box2 = $this->makeCarton($shipment, 'BOX-002', ['gross_weight' => 15.0]);

        $this->addContent($box1, $items['machine']->id, 1, $setId, 'Frame');
        $this->addContent($box2, $items['machine']->id, 1, $setId, 'Accessories');

        $data = $this->getData($shipment);

        $this->assertEquals(2, $data['totals']['total_packages']);
        // Grand total sums pieces across all cartons (matches visual EQUIP QTY column sum).
        // Frame (1) + Accessories (1) = 2, even though it represents one logical machine.
        $this->assertEquals(2, $data['totals']['total_equipment_qty']);
        $this->assertEqualsWithDelta(65.0, $data['totals']['total_gross_weight'], 0.01);

        $this->assertCount(2, $data['container_groups'][0]['lines']);

        // Both lines should show the part_label in product_name
        $productNames = array_map(fn ($l) => $l['product_name'], $data['container_groups'][0]['lines']);
        $this->assertStringContainsString('Frame', $productNames[0]);
        $this->assertStringContainsString('Accessories', $productNames[1]);
    }

    public function test_scenario_4_multi_box_plus_sharing(): void
    {
        [$shipment, $items] = $this->makeShipmentWithItems([
            'machine' => 1,
            'sandals' => 5,
            'socks' => 3,
        ]);

        $setId = (string) Str::ulid();
        $items['machine']->update([
            'packing_split' => ['set_id' => $setId, 'part_labels' => ['Frame', 'Accessories']],
        ]);

        $box1 = $this->makeCarton($shipment, 'BOX-001', ['gross_weight' => 50.0]);
        $box2 = $this->makeCarton($shipment, 'BOX-002', ['gross_weight' => 20.0]);

        $this->addContent($box1, $items['machine']->id, 1, $setId, 'Frame');
        $this->addContent($box2, $items['machine']->id, 1, $setId, 'Accessories');
        $this->addContent($box2, $items['sandals']->id, 5);
        $this->addContent($box2, $items['socks']->id, 3);

        $data = $this->getData($shipment);

        $this->assertEquals(2, $data['totals']['total_packages']);
        // Frame (1) + Accessories (1) + 5 sandals + 3 socks = 10
        $this->assertEquals(10, $data['totals']['total_equipment_qty']);

        // BOX-001: 1 line, BOX-002: 3 lines (1 main + 2 sub-items) = 4 total
        $this->assertCount(4, $data['container_groups'][0]['lines']);

        // Count sub-items: should be 2 (the sandals + socks under BOX-002)
        $subItems = array_filter($data['container_groups'][0]['lines'], fn ($l) => $l['is_sub_item']);
        $this->assertCount(2, $subItems);
    }

    public function test_grouping_by_container_number(): void
    {
        [$shipment, $items] = $this->makeShipmentWithItems(['sandals' => 20]);

        $this->addContent(
            $this->makeCarton($shipment, 'BOX-001', ['container_number' => 'CCLU1111', 'gross_weight' => 5.0]),
            $items['sandals']->id,
            10,
        );
        $this->addContent(
            $this->makeCarton($shipment, 'BOX-002', ['container_number' => 'CCLU1111', 'gross_weight' => 5.0]),
            $items['sandals']->id,
            5,
        );
        $this->addContent(
            $this->makeCarton($shipment, 'BOX-003', ['container_number' => 'CCLU2222', 'gross_weight' => 5.0]),
            $items['sandals']->id,
            5,
        );

        $data = $this->getData($shipment);

        $this->assertCount(2, $data['container_groups']);
        $this->assertTrue($data['has_multiple_containers']);

        $containerA = collect($data['container_groups'])->firstWhere('container_number', 'CCLU1111');
        $containerB = collect($data['container_groups'])->firstWhere('container_number', 'CCLU2222');

        $this->assertNotNull($containerA);
        $this->assertNotNull($containerB);
        $this->assertCount(2, $containerA['lines']);
        $this->assertCount(1, $containerB['lines']);
    }

    public function test_a_pallet_counts_as_one_package_in_the_totals(): void
    {
        [$shipment, $items] = $this->makeShipmentWithItems(['sandals' => 50]);

        $container = ShipmentContainer::create([
            'shipment_id' => $shipment->id,
            'label' => 'CONT-001',
            'container_number' => 'CCLU1111',
        ]);

        $pallet = ShipmentPallet::create([
            'shipment_id' => $shipment->id,
            'shipment_container_id' => $container->id,
            'label' => 'PLT-001',
        ]);

        // 3 caixas soltas no container + 2 caixas em cima do pallet.
        foreach (['BOX-001', 'BOX-002', 'BOX-003'] as $label) {
            $this->addContent(
                $this->makeCarton($shipment, $label, [
                    'shipment_container_id' => $container->id,
                    'gross_weight' => 10.0,
                ]),
                $items['sandals']->id,
                10,
            );
        }

        foreach (['BOX-004', 'BOX-005'] as $label) {
            $this->addContent(
                $this->makeCarton($shipment, $label, [
                    'shipment_container_id' => $container->id,
                    'shipment_pallet_id' => $pallet->id,
                    'gross_weight' => 10.0,
                ]),
                $items['sandals']->id,
                10,
            );
        }

        $data = $this->getData($shipment);

        // 3 caixas soltas + 1 pallet = 4 bultos; as 5 caixas seguem no detalhe.
        $this->assertEquals(4, $data['totals']['total_packages']);
        $this->assertEquals(5, $data['totals']['total_cartons']);
        $this->assertEquals(1, $data['totals']['pallets']);
        $this->assertEquals(50, $data['totals']['total_equipment_qty']);
        $this->assertEqualsWithDelta(50.0, $data['totals']['total_gross_weight'], 0.01);

        $group = $data['container_groups'][0];
        $this->assertEquals(4, $group['totals']['packages']);
        $this->assertEquals(5, $group['totals']['cartons']);
        $this->assertEquals(1, $group['totals']['pallets']);
    }

    public function test_pallet_gets_its_own_line_carrying_weight_and_cubic(): void
    {
        [$shipment, $items] = $this->makeShipmentWithItems(['sandals' => 30]);

        $pallet = ShipmentPallet::create([
            'shipment_id' => $shipment->id,
            'label' => 'PLT-001',
            'gross_weight' => 430.0,
            'length' => 115,
            'width' => 150,
            'height' => 100,
        ]);

        // 1 caixa solta + 2 caixas em cima do pallet.
        $this->addContent(
            $this->makeCarton($shipment, 'BOX-001', ['gross_weight' => 10.0, 'net_weight' => 9.0, 'volume' => 0.1]),
            $items['sandals']->id,
            10,
        );

        foreach (['BOX-002', 'BOX-003'] as $label) {
            $this->addContent(
                $this->makeCarton($shipment, $label, [
                    'shipment_pallet_id' => $pallet->id,
                    'gross_weight' => 10.0,
                    'net_weight' => 9.0,
                    'volume' => 0.1,
                ]),
                $items['sandals']->id,
                10,
            );
        }

        $data = $this->getData($shipment);
        $lines = collect($data['container_groups'][0]['lines']);

        // O pallet vira linha, com o peso pesado e o cubo do conjunto.
        $palletLine = $lines->firstWhere('package_no', 'PLT-001');
        $this->assertNotNull($palletLine, 'faltou a linha do pallet');
        $this->assertEquals(1, $palletLine['package_qty']);
        $this->assertEquals('430.00', $palletLine['gross_weight']);
        $this->assertEquals('1.73', $palletLine['volume']);
        $this->assertStringContainsString('115.0', $palletLine['dimensions']);
        $this->assertEquals('PALLET', $palletLine['packaging_type']);

        // As caixas em cima do pallet não repetem bulto, peso bruto nem cubo.
        $boxesOnPallet = $lines->filter(fn ($l) => $l['pallet'] === 'PLT-001' && $l['package_no'] !== 'PLT-001');
        $this->assertTrue($boxesOnPallet->isNotEmpty(), 'as caixas do pallet sumiram do documento');
        foreach ($boxesOnPallet as $line) {
            $this->assertSame('', (string) $line['package_qty']);
            $this->assertSame('', (string) $line['gross_weight']);
            $this->assertSame('', (string) $line['volume']);
            // Produto, quantidade e líquido continuam na caixa.
            $this->assertNotSame('', (string) $line['net_weight']);
            $this->assertGreaterThan(0, (int) $line['equipment_qty']);
        }

        // Totais: 10 + 430 de peso, 0.1 + 1.725 de cubo, 2 bultos.
        $this->assertEquals(2, $data['totals']['total_packages']);
        $this->assertEqualsWithDelta(440.0, $data['totals']['total_gross_weight'], 0.001);
        $this->assertEqualsWithDelta(1.825, $data['totals']['total_volume'], 0.001);
        $this->assertEqualsWithDelta(27.0, $data['totals']['total_net_weight'], 0.001);
        $this->assertEquals(30, $data['totals']['total_equipment_qty']);
    }

    public function test_a_pallet_with_a_single_box_is_one_line_carrying_product_and_weight(): void
    {
        [$shipment, $items] = $this->makeShipmentWithItems(['plates 10kg' => 120, 'plates 20kg' => 2]);

        $pallet = ShipmentPallet::create([
            'shipment_id' => $shipment->id,
            'label' => 'PLT-006',
            'gross_weight' => 1298.6,
        ]);

        // Uma "caixa" só no pallet: é o conteúdo do pallet, não um volume à parte.
        $box = $this->makeCarton($shipment, 'BOX-044', [
            'shipment_pallet_id' => $pallet->id,
            'gross_weight' => 1298.6,
            'net_weight' => 1240.0,
            'volume' => 0.79,
        ]);
        $this->addContent($box, $items['plates 10kg']->id, 120);
        $this->addContent($box, $items['plates 20kg']->id, 2);

        $data = $this->getData($shipment);
        $lines = collect($data['container_groups'][0]['lines']);

        // Não existe mais uma linha "Pallet · 1 box" sem produto: são só as
        // duas linhas de conteúdo.
        $this->assertNull($lines->first(fn ($l) => str_starts_with((string) $l['product_name'], 'Pallet ·')));
        $this->assertCount(2, $lines);

        // A 1ª linha é o pallet E o produto ao mesmo tempo.
        $first = $lines[0];
        $this->assertSame('PLT-006', $first['package_no']);
        $this->assertSame('PALLET', $first['packaging_type']);
        $this->assertNull($first['pallet']);
        $this->assertEquals(1, $first['package_qty']);
        $this->assertEquals('1,298.60', $first['gross_weight']);
        $this->assertEquals('0.79', $first['volume']);
        $this->assertEquals('1,240.00', $first['net_weight']);
        $this->assertEquals(120, $first['equipment_qty']);
        $this->assertNotSame('', (string) $first['product_name']);

        // O 2º produto da mesma caixa segue como sub-item, sem bulto/peso/cubo.
        $second = $lines[1];
        $this->assertTrue($second['is_sub_item']);
        $this->assertEquals(2, $second['equipment_qty']);
        $this->assertSame('', (string) $second['package_qty']);
        $this->assertSame('', (string) $second['gross_weight']);
        $this->assertSame('', (string) $second['volume']);

        // E as colunas continuam fechando com o total: 1 bulto, 1.298,6 kg, 0,79 m³.
        $this->assertEquals(1, $data['totals']['total_packages']);
        $this->assertEqualsWithDelta(1298.6, $data['totals']['total_gross_weight'], 0.001);
        $this->assertEqualsWithDelta(0.79, $data['totals']['total_volume'], 0.001);
        $sum = fn (string $key) => $lines->sum(fn ($l) => (float) str_replace(',', '', (string) $l[$key]));
        $this->assertEqualsWithDelta($data['totals']['total_gross_weight'], $sum('gross_weight'), 0.01);
        $this->assertEqualsWithDelta($data['totals']['total_volume'], $sum('volume'), 0.01);
        $this->assertEqualsWithDelta($data['totals']['total_packages'], $sum('package_qty'), 0.001);
    }

    public function test_a_shared_box_splits_its_net_weight_by_the_products_own_weights(): void
    {
        [$shipment, $items] = $this->makeShipmentWithItems(['plate 2.5kg' => 60, 'plate 5kg' => 60, 'plate 10kg' => 100]);

        // O cadastro sabe quanto pesa cada anilha — é o que está no nome.
        foreach ([2.5, 5, 10] as $kg) {
            $product = Product::factory()->create();
            $product->specification()->create(['net_weight' => $kg]);
            $items["plate {$kg}kg"]->proformaInvoiceItem->update(['product_id' => $product->id]);
        }

        $pallet = ShipmentPallet::create(['shipment_id' => $shipment->id, 'label' => 'PLT-002', 'gross_weight' => 1523.0]);
        $box = $this->makeCarton($shipment, 'BOX-059', [
            'shipment_pallet_id' => $pallet->id,
            'gross_weight' => 1523.0,
            'net_weight' => 1450.0,
            'volume' => 1.476,
        ]);
        $this->addContent($box, $items['plate 2.5kg']->id, 60);
        $this->addContent($box, $items['plate 5kg']->id, 60);
        $this->addContent($box, $items['plate 10kg']->id, 100);

        $lines = collect($this->getData($shipment)['container_groups'][0]['lines']);

        // 60 × 2,5 + 60 × 5 + 100 × 10 = 1.450: cada linha com o seu, e a
        // soma da coluna é o líquido da caixa.
        $this->assertSame(['150.00', '300.00', '1,000.00'], $lines->pluck('net_weight')->all());
    }

    public function test_a_shared_box_without_product_weights_keeps_the_net_weight_on_the_first_line(): void
    {
        [$shipment, $items] = $this->makeShipmentWithItems(['rack A' => 6, 'rack B' => 6]);

        $box = $this->makeCarton($shipment, 'BOX-057', ['gross_weight' => 358.0, 'net_weight' => 342.0]);
        $this->addContent($box, $items['rack A']->id, 6);
        $this->addContent($box, $items['rack B']->id, 6);

        $lines = collect($this->getData($shipment)['container_groups'][0]['lines']);

        // Sem peso no cadastro não há como repartir sem inventar: fica como era.
        $this->assertSame(['342.00', ''], $lines->pluck('net_weight')->all());
    }

    public function test_document_columns_add_up_to_the_grand_total(): void
    {
        [$shipment, $items] = $this->makeShipmentWithItems(['sandals' => 40]);

        $pallet = ShipmentPallet::create([
            'shipment_id' => $shipment->id,
            'label' => 'PLT-001',
            'gross_weight' => 430.0,
            'length' => 115,
            'width' => 150,
            'height' => 100,
        ]);

        $this->addContent(
            $this->makeCarton($shipment, 'BOX-001', ['gross_weight' => 10.0, 'net_weight' => 9.0, 'volume' => 0.1]),
            $items['sandals']->id,
            10,
        );

        foreach (['BOX-002', 'BOX-003', 'BOX-004'] as $label) {
            $this->addContent(
                $this->makeCarton($shipment, $label, [
                    'shipment_pallet_id' => $pallet->id,
                    'gross_weight' => 10.0,
                    'net_weight' => 9.0,
                    'volume' => 0.1,
                ]),
                $items['sandals']->id,
                10,
            );
        }

        $data = $this->getData($shipment);
        $lines = collect($data['container_groups'][0]['lines']);

        $sum = fn (string $key) => $lines->sum(fn ($l) => (float) str_replace(',', '', (string) $l[$key]));

        // A soma visual de cada coluna tem que fechar com o GRAND TOTAL —
        // é isso que um despachante confere. Tolerância = arredondamento de exibição.
        $this->assertEqualsWithDelta($data['totals']['total_packages'], $sum('package_qty'), 0.001);
        $this->assertEqualsWithDelta($data['totals']['total_equipment_qty'], $sum('equipment_qty'), 0.001);
        $this->assertEqualsWithDelta($data['totals']['total_gross_weight'], $sum('gross_weight'), 0.01);
        $this->assertEqualsWithDelta($data['totals']['total_net_weight'], $sum('net_weight'), 0.01);
        $this->assertEqualsWithDelta($data['totals']['total_volume'], $sum('volume'), 0.01);
    }

    public function test_package_no_uses_carton_label(): void
    {
        [$shipment, $items] = $this->makeShipmentWithItems(['widget' => 5]);

        $this->addContent(
            $this->makeCarton($shipment, 'BOX-042', ['gross_weight' => 10.0]),
            $items['widget']->id,
            5,
        );

        $data = $this->getData($shipment);

        $this->assertEquals('BOX-042', $data['container_groups'][0]['lines'][0]['package_no']);
    }
}
