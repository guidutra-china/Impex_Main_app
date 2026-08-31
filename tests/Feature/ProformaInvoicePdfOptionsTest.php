<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Pdf\Templates\ProformaInvoicePdfTemplate;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Opções do PDF da Proforma Invoice: descrição limitada, coluna Model Number
 * (código do cliente > model_number do produto) e flags de exibição das
 * colunas Product Code (SKU) e Model Number.
 */
class ProformaInvoicePdfOptionsTest extends TestCase
{
    use RefreshDatabase;

    private ProformaInvoice $pi;

    private Company $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Company::factory()->create();
        $this->pi = ProformaInvoice::factory()->create(['company_id' => $this->client->id]);
    }

    private function addItem(Product $product, string $description): ProformaInvoiceItem
    {
        return ProformaInvoiceItem::create([
            'proforma_invoice_id' => $this->pi->id,
            'product_id' => $product->id,
            'description' => $description,
            'quantity' => 10,
            'unit_price' => 1000,
            'unit' => 'pcs',
        ]);
    }

    public function test_description_is_limited_and_model_number_prefers_client_code(): void
    {
        $withPivot = Product::factory()->create(['model_number' => 'MOD-X', 'sku' => 'SKU-X']);
        $withPivot->companies()->attach($this->client->id, ['role' => 'client', 'external_code' => 'CLIENT-77']);
        // Lista longa SEM espaço após as vírgulas — o caso que estourava a
        // largura da tabela no DomPDF (token inquebrável).
        $this->addItem($withPivot, 'Fits: '.str_repeat('9560STS,9570STS,9650CTS,', 15));

        $plain = Product::factory()->create(['model_number' => 'MOD-Y', 'sku' => 'SKU-Y']);
        $this->addItem($plain, 'Short description');

        $template = new ProformaInvoicePdfTemplate($this->pi->fresh(), 'en');
        $data = $template->getData();

        $items = $data['items'];

        // Description capped (150 chars + "..." suffix from Str::limit).
        $this->assertLessThanOrEqual(153, mb_strlen($items[0]['description']));
        $this->assertStringEndsWith('...', $items[0]['description']);
        $this->assertSame('Short description', $items[1]['description']);

        // Commas become breakable (", ") so DomPDF can wrap the list instead
        // of pushing the Qty/Price/Total columns off the page.
        $this->assertStringContainsString('9560STS, 9570STS', $items[0]['description']);
        $this->assertStringNotContainsString('9560STS,9570STS', $items[0]['description']);

        // Client external code wins; falls back to the product's model number.
        $this->assertSame('CLIENT-77', $items[0]['model_number']);
        $this->assertSame('MOD-Y', $items[1]['model_number']);

        // Product Code column still carries the SKU.
        $this->assertSame('SKU-X', $items[0]['product_code']);

        // Defaults: model number shown, SKU column hidden.
        $this->assertTrue($data['show_model_number']);
        $this->assertFalse($data['show_product_code']);
    }

    public function test_product_attributes_are_listed_under_the_description(): void
    {
        $category = \App\Domain\Catalog\Models\Category::create(['name' => 'Pulleys', 'slug' => 'pulleys']);

        $material = \App\Domain\Catalog\Models\CategoryAttribute::create([
            'category_id' => $category->id,
            'name' => 'Material',
            'type' => 'text',
            'sort_order' => 2,
        ]);
        $diameter = \App\Domain\Catalog\Models\CategoryAttribute::create([
            'category_id' => $category->id,
            'name' => 'Diameter',
            'type' => 'number',
            'unit' => 'mm',
            'sort_order' => 1,
        ]);

        $withAttributes = Product::factory()->create();
        $withAttributes->attributeValues()->createMany([
            ['category_attribute_id' => $material->id, 'value' => 'Steel'],
            ['category_attribute_id' => $diameter->id, 'value' => '120'],
            // Valor em branco não vira ruído no documento.
            ['category_attribute_id' => \App\Domain\Catalog\Models\CategoryAttribute::create([
                'category_id' => $category->id,
                'name' => 'Finish',
                'type' => 'text',
                'sort_order' => 3,
            ])->id, 'value' => null],
        ]);
        $this->addItem($withAttributes, 'Pulley');

        $this->addItem(Product::factory()->create(), 'No attributes here');

        $items = (new ProformaInvoicePdfTemplate($this->pi->fresh(), 'en'))->getData()['items'];

        // Ordem definida na categoria (sort_order), unidade junto do valor.
        $this->assertSame('Diameter: 120 mm | Material: Steel', $items[0]['attributes']);
        $this->assertStringNotContainsString('Finish', $items[0]['attributes']);

        // Sem atributos → null, para o template não imprimir linha vazia.
        $this->assertNull($items[1]['attributes']);
    }

    public function test_hide_commission_removes_the_service_fee_line(): void
    {
        $this->addItem(Product::factory()->create(), 'Item');

        \App\Domain\Financial\Models\AdditionalCost::create([
            'costable_type' => ProformaInvoice::class,
            'costable_id' => $this->pi->id,
            'cost_type' => \App\Domain\Financial\Enums\AdditionalCostType::COMMISSION,
            'commission_mode' => \App\Domain\Quotations\Enums\CommissionType::SEPARATE,
            'description' => 'Service Fee',
            'amount' => 500000,
            'currency_code' => 'USD',
            'amount_in_document_currency' => 500000,
            'billable_to' => \App\Domain\Financial\Enums\BillableTo::CLIENT,
            'cost_date' => now()->toDateString(),
        ]);

        $visible = (new ProformaInvoicePdfTemplate($this->pi->fresh(), 'en'))->getData();
        $this->assertNotEmpty($visible['service_fees']);

        $hidden = (new ProformaInvoicePdfTemplate($this->pi->fresh(), 'en', hideCommission: true))->getData();
        $this->assertEmpty($hidden['service_fees']);

        // Escondida a linha, o total volta a ser apenas o subtotal dos itens.
        $this->assertSame($hidden['totals']['subtotal'], $hidden['totals']['grand_total']);
        $this->assertNotSame($visible['totals']['subtotal'], $visible['totals']['grand_total']);
    }

    /**
     * O commission_mode não é consultado pelo GeneratePaymentScheduleAction: ele
     * gera parcela para todo custo billable_to=client. Filtrar EMBEDDED aqui
     * fazia a PI impressa mostrar um grand total menor do que o cronograma
     * cobra (prod: PI-2026-00078, 4.325,06 impresso contra 4.544,20 cobrados).
     * Quem quer a PI sem a comissão usa a opção hideCommission.
     */
    public function test_embedded_commission_is_listed_and_counted_in_the_grand_total(): void
    {
        $this->addItem(Product::factory()->create(), 'Item');

        \App\Domain\Financial\Models\AdditionalCost::create([
            'costable_type' => ProformaInvoice::class,
            'costable_id' => $this->pi->id,
            'cost_type' => \App\Domain\Financial\Enums\AdditionalCostType::COMMISSION,
            'commission_mode' => \App\Domain\Quotations\Enums\CommissionType::EMBEDDED,
            'commission_rate' => 5,
            'description' => 'Embedded commission',
            'amount' => 500000,
            'currency_code' => 'USD',
            'amount_in_document_currency' => 500000,
            'billable_to' => \App\Domain\Financial\Enums\BillableTo::CLIENT,
            'cost_date' => now()->toDateString(),
        ]);

        $data = (new ProformaInvoicePdfTemplate($this->pi->fresh(), 'en'))->getData();

        $this->assertCount(1, $data['service_fees']);
        $this->assertSame('Embedded commission', $data['service_fees'][0]['description']);
        $this->assertNotSame($data['totals']['subtotal'], $data['totals']['grand_total']);

        // hideCommission continua sendo a forma de omitir a linha.
        $hidden = (new ProformaInvoicePdfTemplate($this->pi->fresh(), 'en', hideCommission: true))->getData();
        $this->assertEmpty($hidden['service_fees']);
        $this->assertSame($hidden['totals']['subtotal'], $hidden['totals']['grand_total']);
    }

    public function test_modal_checkbox_names_map_onto_the_template_constructor(): void
    {
        $this->addItem(Product::factory()->create(), 'Item');

        // Nomes usados nos formSchema dos modais Generate/Preview PDF.
        $formData = [
            'with_images' => false,
            'show_model_number' => false,
            'show_product_code' => true,
            'hide_commission' => true,
        ];

        $method = new \ReflectionMethod(\App\Filament\Actions\GeneratePdfAction::class, 'createTemplate');
        /** @var ProformaInvoicePdfTemplate $template */
        $template = $method->invoke(null, ProformaInvoicePdfTemplate::class, $this->pi->fresh(), $formData);

        $data = $template->getData();

        $this->assertTrue($data['show_product_code']);
        $this->assertFalse($data['show_model_number']);
        $this->assertEmpty($data['service_fees']);
    }

    public function test_show_flags_flow_from_constructor_to_view_data(): void
    {
        $this->addItem(Product::factory()->create(), 'Item');

        $template = new ProformaInvoicePdfTemplate(
            $this->pi->fresh(),
            'en',
            hideCommission: false,
            withImages: false,
            showProductCode: true,
            showModelNumber: false,
        );
        $data = $template->getData();

        $this->assertTrue($data['show_product_code']);
        $this->assertFalse($data['show_model_number']);
    }
}
