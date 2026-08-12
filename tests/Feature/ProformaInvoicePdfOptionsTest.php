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
        $this->addItem($withPivot, str_repeat('Fits Case-IH Combines 1620 1640 ', 20));

        $plain = Product::factory()->create(['model_number' => 'MOD-Y', 'sku' => 'SKU-Y']);
        $this->addItem($plain, 'Short description');

        $template = new ProformaInvoicePdfTemplate($this->pi->fresh(), 'en');
        $data = $template->getData();

        $items = $data['items'];

        // Description capped (150 chars + "..." suffix from Str::limit).
        $this->assertLessThanOrEqual(153, mb_strlen($items[0]['description']));
        $this->assertStringEndsWith('...', $items[0]['description']);
        $this->assertSame('Short description', $items[1]['description']);

        // Client external code wins; falls back to the product's model number.
        $this->assertSame('CLIENT-77', $items[0]['model_number']);
        $this->assertSame('MOD-Y', $items[1]['model_number']);

        // Product Code column still carries the SKU.
        $this->assertSame('SKU-X', $items[0]['product_code']);

        // Defaults: model number shown, SKU column hidden.
        $this->assertTrue($data['show_model_number']);
        $this->assertFalse($data['show_product_code']);
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
