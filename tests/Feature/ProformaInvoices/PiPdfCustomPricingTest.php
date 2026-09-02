<?php

namespace Tests\Feature\ProformaInvoices;

use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Pdf\Templates\ProformaInvoicePdfTemplate;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O PDF da PI absorveu o antigo "Custom Price PDF": as opções de preço
 * diferenciado passaram para o Generate/Preview, e o documento com preço
 * alterado continua sendo arquivado à parte.
 */
class PiPdfCustomPricingTest extends TestCase
{
    use RefreshDatabase;

    private ProformaInvoice $pi;

    private Company $client;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Company::factory()->create();
        $this->pi = ProformaInvoice::factory()->create([
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
        ]);
        $this->product = Product::factory()->create(['name' => 'Pulley']);

        ProformaInvoiceItem::create([
            'proforma_invoice_id' => $this->pi->id,
            'product_id' => $this->product->id,
            'description' => 'Pulley',
            'quantity' => 10,
            'unit_price' => 100 * Money::SCALE,
            'unit' => 'pcs',
            'sort_order' => 0,
        ]);
    }

    private function data(array $args = []): array
    {
        return (new ProformaInvoicePdfTemplate(
            $this->pi->fresh(),
            'en',
            priceFormula: $args['priceFormula'] ?? null,
            useCustomPrices: $args['useCustomPrices'] ?? false,
        ))->getData();
    }

    public function test_without_price_options_the_pi_price_is_printed(): void
    {
        $data = $this->data();

        $this->assertSame('100.0000', $data['items'][0]['unit_price']);
        $this->assertSame('1,000.00', $data['items'][0]['line_total']);
    }

    public function test_formula_changes_unit_price_line_total_and_subtotal(): void
    {
        $data = $this->data(['priceFormula' => '*0.70']);

        $this->assertSame('70.0000', $data['items'][0]['unit_price']);
        $this->assertSame('700.00', $data['items'][0]['line_total']);
        $this->assertSame('700.00', $data['totals']['subtotal']);
    }

    public function test_client_custom_price_is_used_only_when_asked(): void
    {
        CompanyProduct::create([
            'company_id' => $this->client->id,
            'product_id' => $this->product->id,
            'role' => 'client',
            'unit_price' => 0,
            'custom_price' => 80 * Money::SCALE,
        ]);

        $this->assertSame('100.0000', $this->data()['items'][0]['unit_price']);
        $this->assertSame('80.0000', $this->data(['useCustomPrices' => true])['items'][0]['unit_price']);
    }

    public function test_custom_price_falls_back_to_the_pi_price_when_none_is_registered(): void
    {
        $data = $this->data(['useCustomPrices' => true]);

        $this->assertSame('100.0000', $data['items'][0]['unit_price']);
    }

    public function test_documents_with_changed_prices_are_archived_apart(): void
    {
        $normal = new ProformaInvoicePdfTemplate($this->pi->fresh(), 'en');
        $withFormula = new ProformaInvoicePdfTemplate($this->pi->fresh(), 'en', priceFormula: '*0.70');
        $withCustom = new ProformaInvoicePdfTemplate($this->pi->fresh(), 'en', useCustomPrices: true);

        $this->assertSame('proforma_invoice_pdf', $normal->getDocumentType());
        $this->assertSame('custom_price_pdf', $withFormula->getDocumentType());
        $this->assertSame('custom_price_pdf', $withCustom->getDocumentType());

        $this->assertStringStartsWith('Custom-', $withFormula->getFilename());
        $this->assertStringNotContainsString('Custom-', $normal->getFilename());
    }

    public function test_blank_formula_is_treated_as_no_override(): void
    {
        $template = new ProformaInvoicePdfTemplate($this->pi->fresh(), 'en', priceFormula: '');

        $this->assertSame('proforma_invoice_pdf', $template->getDocumentType());
        $this->assertSame('100.0000', $template->getData()['items'][0]['unit_price']);
    }
}
