<?php

namespace Tests\Feature\Console;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Quotations\Enums\CommissionType;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Quotations\Models\QuotationItem;
use App\Domain\Settings\Enums\ExchangeRateStatus;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use App\Domain\SupplierQuotations\Models\SupplierQuotationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillQuotationFxSnapshotsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $usd = Currency::create([
            'code' => 'USD', 'name' => 'US Dollar', 'name_plural' => 'US Dollars',
            'symbol' => '$', 'decimal_places' => 2, 'is_base' => true, 'is_active' => true,
        ]);
        $cny = Currency::create([
            'code' => 'CNY', 'name' => 'Chinese Yuan', 'name_plural' => 'Chinese Yuan',
            'symbol' => '¥', 'decimal_places' => 2, 'is_base' => false, 'is_active' => true,
        ]);
        ExchangeRate::create([
            'base_currency_id' => $usd->id, 'target_currency_id' => $cny->id,
            'rate' => 7.0, 'inverse_rate' => 1 / 7.0,
            'date' => today()->subYear()->toDateString(),
            'status' => ExchangeRateStatus::APPROVED,
        ]);
    }

    public function test_backfills_resolved_legacy_and_missing_buckets(): void
    {
        $client = Company::factory()->create();
        $supplier = Company::factory()->create();
        $product = Product::factory()->create();
        $inquiry = Inquiry::factory()->create(['company_id' => $client->id]);
        $quotation = Quotation::create([
            'reference' => 'Q-BF-1',
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'status' => QuotationStatus::DRAFT,
            'currency_code' => 'USD',
            'commission_type' => CommissionType::EMBEDDED,
            'commission_rate' => 0,
        ]);

        // Resolved-from-source: cost_currency NULL but has SQ in CNY.
        $sq = SupplierQuotation::create([
            'reference' => 'SQ-BF-1', 'inquiry_id' => $inquiry->id,
            'company_id' => $supplier->id, 'currency_code' => 'CNY',
            'status' => SupplierQuotationStatus::RECEIVED,
        ]);
        $sqItem = SupplierQuotationItem::create([
            'supplier_quotation_id' => $sq->id, 'product_id' => $product->id,
            'quantity' => 1, 'unit_cost' => 7000,
        ]);
        $resolved = QuotationItem::create([
            'quotation_id' => $quotation->id, 'product_id' => $product->id,
            'supplier_quotation_item_id' => $sqItem->id,
            'quantity' => 1, 'unit_cost' => 7000,
            'unit_price' => 1000, 'commission_rate' => 0,
        ]);

        // Legacy (no source SQ).
        $legacy = QuotationItem::create([
            'quotation_id' => $quotation->id,
            'product_id' => Product::factory()->create()->id,
            'quantity' => 1, 'unit_cost' => 1000,
            'unit_price' => 1500, 'commission_rate' => 0,
        ]);

        $this->artisan('quotations:backfill-fx-snapshots')->assertExitCode(0);

        $resolved->refresh();
        $this->assertSame('CNY', $resolved->cost_currency_code);
        $this->assertEqualsWithDelta(1 / 7.0, (float) $resolved->cost_exchange_rate, 0.0001);

        $legacy->refresh();
        $this->assertSame('USD', $legacy->cost_currency_code);
        $this->assertEqualsWithDelta(1.0, (float) $legacy->cost_exchange_rate, 0.0001);
    }

    public function test_dry_run_does_not_persist(): void
    {
        $client = Company::factory()->create();
        $product = Product::factory()->create();
        $inquiry = Inquiry::factory()->create(['company_id' => $client->id]);
        $quotation = Quotation::create([
            'reference' => 'Q-BF-DR', 'inquiry_id' => $inquiry->id,
            'company_id' => $client->id, 'status' => QuotationStatus::DRAFT,
            'currency_code' => 'USD',
            'commission_type' => CommissionType::EMBEDDED, 'commission_rate' => 0,
        ]);
        $item = QuotationItem::create([
            'quotation_id' => $quotation->id, 'product_id' => $product->id,
            'quantity' => 1, 'unit_cost' => 1000,
            'unit_price' => 1500, 'commission_rate' => 0,
        ]);

        $this->artisan('quotations:backfill-fx-snapshots --dry-run')->assertExitCode(0);
        $item->refresh();
        $this->assertNull($item->cost_currency_code);
    }

    public function test_idempotent_after_first_run(): void
    {
        $client = Company::factory()->create();
        $product = Product::factory()->create();
        $inquiry = Inquiry::factory()->create(['company_id' => $client->id]);
        $quotation = Quotation::create([
            'reference' => 'Q-BF-ID', 'inquiry_id' => $inquiry->id,
            'company_id' => $client->id, 'status' => QuotationStatus::DRAFT,
            'currency_code' => 'USD',
            'commission_type' => CommissionType::EMBEDDED, 'commission_rate' => 0,
        ]);
        QuotationItem::create([
            'quotation_id' => $quotation->id, 'product_id' => $product->id,
            'quantity' => 1, 'unit_cost' => 1000,
            'unit_price' => 1500, 'commission_rate' => 0,
        ]);

        $this->artisan('quotations:backfill-fx-snapshots')->assertExitCode(0);
        $this->artisan('quotations:backfill-fx-snapshots')
            ->expectsOutputToContain('0 quotation items')
            ->assertExitCode(0);
    }
}
