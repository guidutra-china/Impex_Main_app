<?php

namespace Tests\Feature\Quotations;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Pdf\Templates\QuotationPdfTemplate;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Quotations\Models\QuotationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Campo opcional "Model Number" na Quotation ao cliente: escolhido na hora
 * de gerar/pré-visualizar, desligado por padrão. Como na PI, o código do
 * cliente (pivot) vence o model number do cadastro.
 */
class QuotationPdfModelNumberTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    private Quotation $quotation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Company::factory()->create();
        $this->quotation = Quotation::factory()->create(['company_id' => $this->client->id]);

        $withPivot = Product::factory()->create(['name' => 'Pulley A', 'model_number' => 'MOD-X', 'sku' => 'SKU-X']);
        $withPivot->companies()->attach($this->client->id, ['role' => 'client', 'external_code' => 'CLIENT-77']);
        $plain = Product::factory()->create(['name' => 'Pulley B', 'model_number' => 'MOD-Y', 'sku' => 'SKU-Y']);

        foreach ([$withPivot, $plain] as $i => $product) {
            QuotationItem::create([
                'quotation_id' => $this->quotation->id,
                'product_id' => $product->id,
                'quantity' => 10,
                'unit' => 'pcs',
                'unit_cost' => 50000,
                'unit_price' => 80000,
                'sort_order' => $i,
            ]);
        }
    }

    public function test_model_number_is_off_by_default_and_resolves_client_code_when_on(): void
    {
        $default = (new QuotationPdfTemplate($this->quotation->fresh(), 'en'))->getData();
        $this->assertFalse($default['show_model_number']);

        $data = (new QuotationPdfTemplate($this->quotation->fresh(), 'en', showModelNumber: true))->getData();

        $this->assertTrue($data['show_model_number']);
        $this->assertSame('CLIENT-77', $data['items'][0]['model_number']);
        $this->assertSame('MOD-Y', $data['items'][1]['model_number']);
        // Product Code column keeps the SKU.
        $this->assertSame('SKU-X', $data['items'][0]['product_code']);
    }

    public function test_pdf_view_renders_the_model_column_only_when_requested(): void
    {
        $off = new QuotationPdfTemplate($this->quotation->fresh(), 'en');
        $htmlOff = view($off->getView(), $off->getData())->render();
        $this->assertStringNotContainsString('CLIENT-77', $htmlOff);

        $on = new QuotationPdfTemplate($this->quotation->fresh(), 'en', showModelNumber: true);
        $htmlOn = view($on->getView(), $on->getData())->render();
        $this->assertStringContainsString('CLIENT-77', $htmlOn);
        $this->assertStringContainsString('MOD-Y', $htmlOn);
    }
}
