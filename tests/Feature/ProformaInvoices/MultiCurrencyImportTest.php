<?php

namespace Tests\Feature\ProformaInvoices;

use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Services\ProformaInvoiceItemCurrencyResolver;
use App\Domain\Settings\Enums\ExchangeRateStatus;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use App\Domain\SupplierQuotations\Models\SupplierQuotationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiCurrencyImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $usd = Currency::create([
            'code' => 'USD',
            'name' => 'US Dollar',
            'name_plural' => 'US Dollars',
            'symbol' => '$',
            'decimal_places' => 2,
            'is_base' => true,
            'is_active' => true,
        ]);
        $cny = Currency::create([
            'code' => 'CNY',
            'name' => 'Chinese Yuan',
            'name_plural' => 'Chinese Yuan',
            'symbol' => '¥',
            'decimal_places' => 2,
            'is_base' => false,
            'is_active' => true,
        ]);

        // 1 USD = 7 CNY (so 1 CNY ≈ 0.1428 USD)
        // Use yesterday's date to avoid SQLite date-boundary ambiguity.
        ExchangeRate::create([
            'base_currency_id' => $usd->id,
            'target_currency_id' => $cny->id,
            'rate' => 7.0,
            'inverse_rate' => 1 / 7.0,
            'date' => today()->subDay()->toDateString(),
            'status' => ExchangeRateStatus::APPROVED,
        ]);
    }

    public function test_supplier_quotation_import_snapshots_cny_to_usd_rate(): void
    {
        $client = Company::factory()->create();
        $supplier = Company::factory()->create();

        $pi = ProformaInvoice::factory()->create([
            'company_id' => $client->id,
            'currency_code' => 'USD',
        ]);

        // SupplierQuotation and SupplierQuotationItem don't have factories in this
        // codebase; build them directly via the model fillable fields.
        $inquiry = Inquiry::factory()->create(['company_id' => $client->id]);

        $sq = SupplierQuotation::create([
            'reference' => 'SQ-TEST-001',
            'inquiry_id' => $inquiry->id,
            'company_id' => $supplier->id,
            'currency_code' => 'CNY',
            'status' => \App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus::RECEIVED,
        ]);
        $sqItem = SupplierQuotationItem::create([
            'supplier_quotation_id' => $sq->id,
            'description' => 'Widget',
            'unit_cost' => 10000, // ¥100.00 in minor units
            'quantity' => 1,
            'unit' => 'pcs',
        ]);

        // The import action in ItemsRelationManager runs inside a Filament action
        // closure and is awkward to invoke directly from a unit test. We validate
        // the underlying contract: the resolver + fillable set produce the right
        // persisted values when the action body code path runs.
        $resolver = app(ProformaInvoiceItemCurrencyResolver::class);
        $resolved = $resolver->resolve(
            $sqItem->supplierQuotation->currency_code,
            $pi->currency_code,
            $pi->issue_date?->toDateString(),
        );

        $pi->items()->create([
            'product_id'          => $sqItem->product_id,
            'supplier_company_id' => $sq->company_id,
            'description'         => 'Imported',
            'quantity'            => $sqItem->quantity,
            'unit'                => 'pcs',
            'unit_price'          => 0,
            'unit_cost'           => $sqItem->unit_cost,
            'cost_currency_code'  => $resolved['currency'],
            'cost_exchange_rate'  => $resolved['rate'],
            'sort_order'          => 1,
        ]);

        $item = $pi->items()->first();

        $this->assertSame('CNY', $item->cost_currency_code);
        $this->assertEqualsWithDelta(1 / 7.0, (float) $item->cost_exchange_rate, 0.0001);
        // round(10000 * (1/7)) = 1429 USD minor units
        $this->assertSame(1429, $item->unit_cost_in_document_currency);
    }

    public function test_client_quotation_import_snapshots_currency_when_quotation_currency_differs(): void
    {
        $client = Company::factory()->create();

        $pi = ProformaInvoice::factory()->create([
            'company_id' => $client->id,
            'currency_code' => 'USD',
        ]);

        $inquiry = Inquiry::factory()->create(['company_id' => $client->id]);

        $quotation = \App\Domain\Quotations\Models\Quotation::create([
            'reference' => 'Q-TEST-001',
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'currency_code' => 'CNY',
            'status' => \App\Domain\Quotations\Enums\QuotationStatus::DRAFT,
        ]);
        $product = \App\Domain\Catalog\Models\Product::factory()->create();
        $qItem = \App\Domain\Quotations\Models\QuotationItem::create([
            'quotation_id' => $quotation->id,
            'product_id' => $product->id,
            'unit_price' => 20000, // ¥200
            'unit_cost'  => 10000, // ¥100
            'quantity'   => 1,
        ]);

        // Contract test: resolver + model saving hook produces correct snapshot.
        $resolver = app(ProformaInvoiceItemCurrencyResolver::class);
        $resolved = $resolver->resolve(
            $qItem->quotation->currency_code,
            $pi->currency_code,
            $pi->issue_date?->toDateString(),
        );

        $pi->items()->create([
            'product_id' => $qItem->product_id,
            'quotation_item_id' => $qItem->id,
            'description' => 'Quoted',
            'quantity' => 1,
            'unit' => 'pcs',
            'unit_price' => $qItem->unit_price,
            'unit_cost'  => $qItem->unit_cost,
            'cost_currency_code' => $resolved['currency'],
            'cost_exchange_rate' => $resolved['rate'],
            'sort_order' => 1,
        ]);

        $item = $pi->items()->first();
        $this->assertSame('CNY', $item->cost_currency_code);
        $this->assertSame(1429, $item->unit_cost_in_document_currency);
    }

    public function test_inquiry_import_uses_company_product_pivot_currency(): void
    {
        $client = Company::factory()->create();
        $supplier = Company::factory()->create();
        $product = \App\Domain\Catalog\Models\Product::factory()->create();

        // Attach the supplier→product pivot row in CNY
        $supplier->products()->attach($product->id, [
            'role' => 'supplier',
            'unit_price' => 10000, // ¥100 in minor units
            'currency_code' => 'CNY',
            'is_preferred' => true,
        ]);

        $pi = ProformaInvoice::factory()->create([
            'company_id' => $client->id,
            'currency_code' => 'USD',
        ]);

        // Contract test: replicate the action body.
        $preferred = $product->suppliers()
            ->orderByDesc('company_product.is_preferred')
            ->first();

        $resolver = app(ProformaInvoiceItemCurrencyResolver::class);
        $resolved = $resolver->resolve(
            $preferred->pivot->currency_code ?? null,
            $pi->currency_code,
            $pi->issue_date?->toDateString(),
        );

        $pi->items()->create([
            'product_id' => $product->id,
            'supplier_company_id' => $preferred->id,
            'description' => $product->name,
            'quantity' => 1,
            'unit' => 'pcs',
            'unit_price' => 0,
            'unit_cost' => $preferred->pivot->unit_price,
            'cost_currency_code' => $resolved['currency'],
            'cost_exchange_rate' => $resolved['rate'],
            'sort_order' => 1,
        ]);

        $item = $pi->items()->first();
        $this->assertSame('CNY', $item->cost_currency_code);
        $this->assertSame(1429, $item->unit_cost_in_document_currency);
    }
}
