<?php

namespace Tests\Feature\Console;

use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplyLastSalePricesCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makePi(Company $client, array $attributes = []): ProformaInvoice
    {
        return ProformaInvoice::factory()->create(['company_id' => $client->id] + $attributes);
    }

    private function makeItem(ProformaInvoice $pi, Product $product, int $unitPrice): ProformaInvoiceItem
    {
        return ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'product_id' => $product->id,
            'description' => $product->name,
            'quantity' => 10,
            'unit' => 'pcs',
            'unit_price' => $unitPrice,
        ]);
    }

    public function test_fills_blank_prices_with_the_clients_most_recent_sale(): void
    {
        $client = Company::factory()->create();
        $product = Product::factory()->create();

        $this->makeItem($this->makePi($client, ['issue_date' => '2025-01-10']), $product, 15000);
        $this->makeItem($this->makePi($client, ['issue_date' => '2026-05-07']), $product, 19525);
        // Não contam: outro cliente, outra moeda, cancelada, excluída.
        $this->makeItem($this->makePi(Company::factory()->create(), ['issue_date' => '2026-09-01']), $product, 99999);
        $this->makeItem($this->makePi($client, ['issue_date' => '2026-09-01', 'currency_code' => 'EUR']), $product, 88888);
        $this->makeItem($this->makePi($client, ['issue_date' => '2026-09-01', 'status' => 'cancelled']), $product, 77777);
        $deleted = $this->makePi($client, ['issue_date' => '2026-09-02']);
        $this->makeItem($deleted, $product, 66666);
        $deleted->delete();

        $target = $this->makePi($client);
        $blank = $this->makeItem($target, $product, 0);
        $priced = $this->makeItem($target, $product, 12345);

        $this->artisan('proforma-invoices:apply-last-sale-prices', ['pi' => $target->reference])
            ->assertSuccessful();

        $this->assertSame(19525, $blank->fresh()->unit_price);
        $this->assertSame(12345, $priced->fresh()->unit_price);
    }

    public function test_falls_back_to_client_link_price_and_overwrite_replaces_filled_prices(): void
    {
        $client = Company::factory()->create();
        $linked = Product::factory()->create();
        $unknown = Product::factory()->create();

        CompanyProduct::create([
            'company_id' => $client->id,
            'product_id' => $linked->id,
            'role' => 'client',
            'unit_price' => 2275,
        ]);

        $target = $this->makePi($client);
        $item = $this->makeItem($target, $linked, 5000);
        $never = $this->makeItem($target, $unknown, 0);

        $this->artisan('proforma-invoices:apply-last-sale-prices', ['pi' => $target->id, '--dry-run' => true, '--overwrite' => true])
            ->assertSuccessful();
        $this->assertSame(5000, $item->fresh()->unit_price);

        $this->artisan('proforma-invoices:apply-last-sale-prices', ['pi' => $target->id, '--overwrite' => true])
            ->assertSuccessful();

        $this->assertSame(2275, $item->fresh()->unit_price);
        $this->assertSame(0, $never->fresh()->unit_price);
    }

    public function test_refuses_proforma_invoices_beyond_draft(): void
    {
        $client = Company::factory()->create();
        $product = Product::factory()->create();
        $this->makeItem($this->makePi($client, ['issue_date' => '2025-01-10']), $product, 15000);

        $sent = $this->makePi($client, ['status' => 'sent']);
        $item = $this->makeItem($sent, $product, 0);

        $this->artisan('proforma-invoices:apply-last-sale-prices', ['pi' => $sent->reference])
            ->assertFailed();

        $this->assertSame(0, $item->fresh()->unit_price);
    }
}
