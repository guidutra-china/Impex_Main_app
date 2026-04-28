<?php

namespace Tests\Feature\Filament;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Inquiries\Models\InquiryItem;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Settings\Enums\ExchangeRateStatus;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use App\Domain\SupplierQuotations\Models\SupplierQuotationItem;
use App\Filament\Resources\Inquiries\Pages\EditInquiry;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class CreateQuotationActionFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_quotation_via_form_action_persists_fx_snapshot(): void
    {
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
        ExchangeRate::create([
            'base_currency_id' => $usd->id,
            'target_currency_id' => $cny->id,
            'rate' => 7.0,
            'inverse_rate' => 1 / 7.0,
            'date' => today()->subDay()->toDateString(),
            'status' => ExchangeRateStatus::APPROVED,
        ]);

        $admin = User::factory()->create();
        $client = Company::factory()->create();
        $supplier = Company::factory()->create();
        $product = Product::factory()->create();

        $inquiry = Inquiry::factory()->create([
            'company_id' => $client->id,
            'currency_code' => 'USD',
        ]);
        InquiryItem::create([
            'inquiry_id' => $inquiry->id,
            'product_id' => $product->id,
            'quantity' => 10,
            'sort_order' => 0,
        ]);

        $sq = SupplierQuotation::create([
            'reference' => 'SQ-FT-001',
            'inquiry_id' => $inquiry->id,
            'company_id' => $supplier->id,
            'currency_code' => 'CNY',
            'status' => SupplierQuotationStatus::RECEIVED,
        ]);
        SupplierQuotationItem::create([
            'supplier_quotation_id' => $sq->id,
            'product_id' => $product->id,
            'quantity' => 10,
            'unit_cost' => 70000,
        ]);

        // Allow this user to bypass the InquiryPolicy gates (the test focuses on
        // the action wiring, not on permissions).
        Gate::before(fn (User $u) => $u->id === $admin->id ? true : null);

        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');

        Livewire::test(EditInquiry::class, ['record' => $inquiry->id])
            ->callAction('createQuotation', data: [
                'supplier_quotation_ids' => [$sq->id],
                'commission_type' => 'embedded',
                'commission_rate' => 10,
                'items_preview' => [
                    [
                        'product_label' => $product->name,
                        'quantity' => 10,
                        'source_sq_label' => 'SQ-FT-001',
                        'unit_cost' => 7.0,
                        'cost_currency_code' => 'CNY',
                        'cost_exchange_rate' => 1 / 7.0,
                        'commission_rate' => 10,
                        'unit_price' => 1.10,
                    ],
                ],
            ])
            ->assertHasNoActionErrors();

        $quotation = $inquiry->quotations()->first();
        $this->assertNotNull($quotation, 'Quotation was not persisted');
        $this->assertSame(QuotationStatus::DRAFT, $quotation->status);
        $this->assertSame('CNY', $quotation->items->first()->cost_currency_code);
        $this->assertSame(11000, $quotation->items->first()->unit_price); // $1.10 in minor units (×10000)
    }
}
