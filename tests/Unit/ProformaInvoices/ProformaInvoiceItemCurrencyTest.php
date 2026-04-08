<?php

namespace Tests\Unit\ProformaInvoices;

use App\Domain\CRM\Models\Company;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProformaInvoiceItemCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private function makePi(string $currency = 'USD'): ProformaInvoice
    {
        $company = Company::factory()->create();
        return ProformaInvoice::factory()->create([
            'company_id' => $company->id,
            'currency_code' => $currency,
        ]);
    }

    public function test_saving_hook_recomputes_cached_doc_currency_cost(): void
    {
        $pi = $this->makePi('USD');

        $item = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description' => 'Widget',
            'quantity' => 10,
            'unit' => 'pcs',
            'unit_price' => 2000,            // $20.00
            'unit_cost' => 10000,            // ¥100.00 in CNY minor units
            'cost_currency_code' => 'CNY',
            'cost_exchange_rate' => 0.142,   // 1 CNY = 0.142 USD
        ]);

        // round(10000 * 0.142) = 1420 (USD minor units = $14.20)
        $this->assertSame(1420, $item->fresh()->unit_cost_in_document_currency);
    }

    public function test_cost_total_uses_doc_currency_cache(): void
    {
        $pi = $this->makePi('USD');

        $item = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description' => 'Widget',
            'quantity' => 10,
            'unit' => 'pcs',
            'unit_price' => 2000,
            'unit_cost' => 10000,
            'cost_currency_code' => 'CNY',
            'cost_exchange_rate' => 0.142,
        ]);

        // 1420 * 10 = 14200
        $this->assertSame(14200, $item->fresh()->cost_total);
    }

    public function test_margin_uses_doc_currency_cost(): void
    {
        $pi = $this->makePi('USD');

        $item = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description' => 'Widget',
            'quantity' => 1,
            'unit' => 'pcs',
            'unit_price' => 2000,            // $20.00
            'unit_cost' => 10000,            // ¥100.00
            'cost_currency_code' => 'CNY',
            'cost_exchange_rate' => 0.142,   // → $14.20
        ]);

        // (2000 - 1420) / 1420 * 100 = 40.85%
        $this->assertEqualsWithDelta(40.85, $item->fresh()->margin, 0.01);
    }

    public function test_same_currency_with_rate_one(): void
    {
        $pi = $this->makePi('USD');

        $item = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description' => 'Widget',
            'quantity' => 5,
            'unit' => 'pcs',
            'unit_price' => 1500,
            'unit_cost' => 1000,
            'cost_currency_code' => 'USD',
            'cost_exchange_rate' => 1,
        ]);

        $this->assertSame(1000, $item->fresh()->unit_cost_in_document_currency);
        $this->assertSame(5000, $item->fresh()->cost_total);
        $this->assertEqualsWithDelta(50.0, $item->fresh()->margin, 0.01);
    }

    public function test_updating_unit_cost_recomputes_cached_value(): void
    {
        $pi = $this->makePi('USD');

        $item = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description' => 'Widget',
            'quantity' => 1,
            'unit' => 'pcs',
            'unit_price' => 2000,
            'unit_cost' => 10000,
            'cost_currency_code' => 'CNY',
            'cost_exchange_rate' => 0.142,
        ]);

        $this->assertSame(1420, $item->fresh()->unit_cost_in_document_currency);

        $item->update(['unit_cost' => 20000]); // ¥200.00
        // round(20000 * 0.142) = 2840
        $this->assertSame(2840, $item->fresh()->unit_cost_in_document_currency);
    }

    public function test_updating_exchange_rate_recomputes_cached_value(): void
    {
        $pi = $this->makePi('USD');

        $item = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description' => 'Widget',
            'quantity' => 1,
            'unit' => 'pcs',
            'unit_price' => 2000,
            'unit_cost' => 10000,
            'cost_currency_code' => 'CNY',
            'cost_exchange_rate' => 0.142,
        ]);

        $this->assertSame(1420, $item->fresh()->unit_cost_in_document_currency);

        $item->update(['cost_exchange_rate' => 0.15]);
        // round(10000 * 0.15) = 1500
        $this->assertSame(1500, $item->fresh()->unit_cost_in_document_currency);
    }

    public function test_unrelated_field_update_does_not_overwrite_cache(): void
    {
        $pi = $this->makePi('USD');

        $item = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description' => 'Widget',
            'quantity' => 1,
            'unit' => 'pcs',
            'unit_price' => 2000,
            'unit_cost' => 10000,
            'cost_currency_code' => 'CNY',
            'cost_exchange_rate' => 0.142,
        ]);

        $this->assertSame(1420, $item->fresh()->unit_cost_in_document_currency);

        // Simulate a manual cache repair, then update an unrelated field.
        $item->forceFill(['unit_cost_in_document_currency' => 9999])->save();
        $this->assertSame(9999, $item->fresh()->unit_cost_in_document_currency);

        $item->update(['notes' => 'changed']);
        // The cache repair should NOT be clobbered because neither unit_cost nor
        // cost_exchange_rate changed.
        $this->assertSame(9999, $item->fresh()->unit_cost_in_document_currency);
    }

    public function test_null_exchange_rate_defaults_to_one(): void
    {
        $pi = $this->makePi('USD');

        $item = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description' => 'Widget',
            'quantity' => 1,
            'unit' => 'pcs',
            'unit_price' => 2000,
            'unit_cost' => 5000,
            'cost_currency_code' => 'USD',
            'cost_exchange_rate' => null,
        ]);

        $fresh = $item->fresh();
        $this->assertSame(5000, $fresh->unit_cost_in_document_currency);
        $this->assertEqualsWithDelta(1.0, (float) $fresh->cost_exchange_rate, 0.0001);
    }
}
