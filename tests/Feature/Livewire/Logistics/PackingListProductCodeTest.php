<?php

namespace Tests\Feature\Livewire\Logistics;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Models\CartonContent;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Livewire\Logistics\PackingListBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * O código do produto (MODEL NO, mesma regra do Packing List) identifica a
 * caixa na conferência — precisa aparecer também nas caixas, não só na lista
 * de produtos.
 */
class PackingListProductCodeTest extends TestCase
{
    use RefreshDatabase;

    private Shipment $shipment;

    /** @var array<string, ShipmentItem> */
    private array $items = [];

    private Company $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Company::create(['name' => 'Client Code PL', 'status' => 'active']);
        $this->client->companyRoles()->create(['role' => 'client']);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-CODE-1',
            'company_id' => $this->client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $pi = ProformaInvoice::create([
            'reference' => 'PI-CODE-1',
            'inquiry_id' => $inquiry->id,
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-08-01',
            'status' => 'confirmed',
        ]);

        $this->shipment = Shipment::create([
            'reference' => 'SH-CODE-1',
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
            'status' => 'draft',
            'transport_mode' => 'sea',
        ]);

        foreach ([['Chest Press', 'DPF-D905Z'], ['Pull Down', 'DPF-D920Z']] as $i => [$name, $clientCode]) {
            $product = Product::factory()->create(['name' => $name, 'model_number' => 'INTERNAL-'.$i]);
            $product->companies()->attach($this->client->id, [
                'role' => 'client',
                'external_code' => $clientCode,
            ]);

            $piItem = ProformaInvoiceItem::create([
                'proforma_invoice_id' => $pi->id,
                'product_id' => $product->id,
                'description' => $name,
                'quantity' => 10,
                'unit_price' => 1000,
                'unit' => 'pcs',
                'sort_order' => $i,
            ]);

            $this->items[$name] = ShipmentItem::create([
                'shipment_id' => $this->shipment->id,
                'proforma_invoice_item_id' => $piItem->id,
                'quantity' => 10,
                'sort_order' => $i,
            ]);
        }
    }

    private function box(string $label, array $contents, int $sortOrder): Carton
    {
        $carton = Carton::create([
            'shipment_id' => $this->shipment->id,
            'label' => $label,
            'packaging_type' => 'CARTON',
            'gross_weight' => 5,
            'net_weight' => 4,
            'volume' => 0.02,
            'sort_order' => $sortOrder,
        ]);

        foreach ($contents as $itemName => $pieces) {
            CartonContent::create([
                'carton_id' => $carton->id,
                'shipment_item_id' => $this->items[$itemName]->id,
                'pieces' => $pieces,
                'sort_order' => 1,
            ]);
        }

        return $carton;
    }

    public function test_product_codes_map_uses_the_client_code(): void
    {
        $codes = Livewire::test(PackingListBuilder::class, ['shipment' => $this->shipment])
            ->instance()
            ->productCodes;

        $this->assertSame('DPF-D905Z', $codes[$this->items['Chest Press']->id]);
        $this->assertSame('DPF-D920Z', $codes[$this->items['Pull Down']->id]);
    }

    public function test_single_product_boxes_show_the_code_next_to_the_name(): void
    {
        // Acima do limiar de 10 caixas, o builder agrupa em subgrupos — é a
        // tela do print, onde só saía o nome.
        for ($i = 1; $i <= 12; $i++) {
            $this->box('BOX-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), ['Chest Press' => 1], $i);
        }

        $html = Livewire::test(PackingListBuilder::class, ['shipment' => $this->shipment])->html();

        // Escopo na região da CAIXA: o código também aparece na lista de
        // produtos acima, então procurar no HTML inteiro passaria mesmo sem a
        // mudança. "pcs / box" só existe na linha de conteúdo do subgrupo.
        $this->assertStringContainsString('DPF-D905Z', $this->boxRegion($html, 'pcs / box'));
    }

    public function test_mixed_boxes_show_the_code_of_each_product(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->box(
                'MIX-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                ['Chest Press' => 1, 'Pull Down' => 1],
                $i,
            );
        }

        $html = Livewire::test(PackingListBuilder::class, ['shipment' => $this->shipment])->html();

        $region = $this->boxRegion($html, 'produtos distintos', after: 1200);

        $this->assertStringContainsString('DPF-D905Z', $region);
        $this->assertStringContainsString('DPF-D920Z', $region);
    }

    /**
     * Trecho do HTML ao redor de uma âncora que só existe dentro do cartão da
     * caixa — evita que a lista de produtos, que também mostra o código,
     * valide o teste por engano.
     */
    private function boxRegion(string $html, string $anchor, int $before = 700, int $after = 300): string
    {
        $position = strpos($html, $anchor);

        $this->assertNotFalse($position, "âncora [{$anchor}] não encontrada no HTML");

        return substr($html, max(0, $position - $before), $before + $after);
    }

    public function test_product_without_client_code_still_renders(): void
    {
        $orphan = Product::factory()->create(['name' => 'Sem Código', 'model_number' => null, 'sku' => '']);
        $orphan->forceFill(['sku' => ''])->saveQuietly();

        $piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => ProformaInvoice::first()->id,
            'product_id' => $orphan->id,
            'description' => 'Sem Código',
            'quantity' => 1,
            'unit_price' => 1000,
            'unit' => 'pcs',
            'sort_order' => 9,
        ]);
        $item = ShipmentItem::create([
            'shipment_id' => $this->shipment->id,
            'proforma_invoice_item_id' => $piItem->id,
            'quantity' => 1,
            'sort_order' => 9,
        ]);

        $component = Livewire::test(PackingListBuilder::class, ['shipment' => $this->shipment]);

        $this->assertNull($component->instance()->productCodes[$item->id]);
        $component->assertSuccessful();
    }
}
