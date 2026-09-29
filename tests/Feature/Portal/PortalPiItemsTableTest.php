<?php

namespace Tests\Feature\Portal;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tabela de itens da PI no Portal do Cliente: mostra o código que o cliente
 * conhece (pivot do cliente > model number > SKU) e os cabeçalhos seguem o
 * idioma do usuário.
 */
class PortalPiItemsTableTest extends TestCase
{
    use RefreshDatabase;

    private ProformaInvoice $pi;

    protected function setUp(): void
    {
        parent::setUp();

        $client = Company::create(['name' => 'Portal Items Client', 'status' => 'active']);
        $client->companyRoles()->create(['role' => 'client']);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-PIT-001',
            'company_id' => $client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $this->pi = ProformaInvoice::create([
            'reference' => 'PI-PIT-001',
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'status' => 'confirmed',
        ]);

        $withClientCode = Product::create(['name' => 'Treadmill X', 'sku' => 'INT-001', 'status' => 'active']);
        $withClientCode->companies()->attach($client->id, ['role' => 'client', 'external_code' => 'DPF-TX9']);

        $modelOnly = Product::create(['name' => 'Bench Y', 'sku' => 'INT-002', 'model_number' => 'BY-200', 'status' => 'active']);

        foreach ([$withClientCode, $modelOnly] as $i => $product) {
            ProformaInvoiceItem::create([
                'proforma_invoice_id' => $this->pi->id,
                'product_id' => $product->id,
                'description' => $product->name,
                'quantity' => 10,
                'unit' => 'pcs',
                'unit_price' => 10_0000,
                'unit_cost' => 5_0000,
                'sort_order' => $i + 1,
            ]);
        }
    }

    private function render(string $locale): string
    {
        app()->setLocale($locale);

        return view('portal.infolists.pi-items-table', [
            'getRecord' => fn () => $this->pi->fresh(),
        ])->render();
    }

    public function test_shows_client_code_falling_back_to_model_number(): void
    {
        $html = $this->render('en');

        $this->assertStringContainsString('DPF-TX9', $html);
        $this->assertStringContainsString('BY-200', $html);
        $this->assertStringNotContainsString('INT-001', $html);
    }

    public function test_headers_follow_user_locale(): void
    {
        $html = $this->render('pt_BR');

        $this->assertStringContainsString('Código', $html);
        $this->assertStringContainsString('Produto', $html);
        $this->assertStringContainsString('Descrição', $html);
        $this->assertStringContainsString('Quantidade', $html);
        $this->assertStringNotContainsString('>Quantity<', $html);
    }
}
