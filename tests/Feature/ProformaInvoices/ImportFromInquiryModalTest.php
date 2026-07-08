<?php

namespace Tests\Feature\ProformaInvoices;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Inquiries\Models\InquiryItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Quotations\Models\QuotationItem;
use App\Filament\Resources\ProformaInvoices\Pages\EditProformaInvoice;
use App\Filament\Resources\ProformaInvoices\RelationManagers\ItemsRelationManager;
use App\Models\User;
use Database\Factories\ProformaInvoiceItemFactory;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class ImportFromInquiryModalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $user = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $user->id ? true : null);
        $this->actingAs($user);
    }

    public function test_options_show_model_number_preferring_client_pivot_code(): void
    {
        $client = Company::factory()->create();
        $inquiry = Inquiry::factory()->create(['company_id' => $client->id]);
        $pi = ProformaInvoice::factory()->create([
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
        ]);

        $withPivot = Product::factory()->create([
            'name' => 'Widget A', 'sku' => 'SKU-A', 'model_number' => 'MOD-A',
        ]);
        $withPivot->companies()->attach($client->id, [
            'role' => 'client', 'external_code' => 'EXT-A',
        ]);

        // Product without client pivot → falls back to its own model_number.
        $plain = Product::factory()->create([
            'name' => 'Widget B', 'sku' => 'SKU-B', 'model_number' => 'MOD-B',
        ]);

        InquiryItem::create([
            'inquiry_id' => $inquiry->id, 'product_id' => $withPivot->id,
            'quantity' => 10, 'unit' => 'pcs', 'sort_order' => 1,
        ]);
        InquiryItem::create([
            'inquiry_id' => $inquiry->id, 'product_id' => $plain->id,
            'quantity' => 5, 'unit' => 'pcs', 'sort_order' => 2,
        ]);

        Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $pi,
            'pageClass' => EditProformaInvoice::class,
        ])
            ->mountTableAction('importFromInquiry')
            ->assertMountedActionModalSee('EXT-A — Widget A')
            ->assertMountedActionModalSee('MOD-B — Widget B')
            ->assertMountedActionModalDontSee('MOD-A'); // pivot code takes priority over the product's own model
    }

    public function test_inquiry_import_options_show_remaining_balance_against_pi_items(): void
    {
        $client = Company::factory()->create();
        $inquiry = Inquiry::factory()->create(['company_id' => $client->id]);
        $pi = ProformaInvoice::factory()->create([
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
        ]);

        $partial = Product::factory()->create(['name' => 'Widget A', 'sku' => 'SKU-A']);
        $untouched = Product::factory()->create(['name' => 'Widget B', 'sku' => 'SKU-B']);

        InquiryItem::create([
            'inquiry_id' => $inquiry->id, 'product_id' => $partial->id,
            'quantity' => 10, 'unit' => 'pcs', 'sort_order' => 1,
        ]);
        InquiryItem::create([
            'inquiry_id' => $inquiry->id, 'product_id' => $untouched->id,
            'quantity' => 5, 'unit' => 'pcs', 'sort_order' => 2,
        ]);

        // 6 of 10 pcs of Widget A already live on the PI.
        ProformaInvoiceItemFactory::new()->create([
            'proforma_invoice_id' => $pi->id,
            'product_id' => $partial->id,
            'quantity' => 6,
        ]);

        Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $pi,
            'pageClass' => EditProformaInvoice::class,
        ])
            ->mountTableAction('importFromInquiry')
            ->assertMountedActionModalSee('Widget A — Qty: 10 | Remaining: 4')
            ->assertMountedActionModalSee('Widget B — Qty: 5 | Remaining: 5');
    }

    public function test_inquiry_import_only_remaining_imports_balance_and_skips_fully_imported(): void
    {
        $client = Company::factory()->create();
        $inquiry = Inquiry::factory()->create(['company_id' => $client->id]);
        $pi = ProformaInvoice::factory()->create([
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
        ]);

        $partial = Product::factory()->create(['name' => 'Widget A', 'sku' => 'SKU-A']);
        $full = Product::factory()->create(['name' => 'Widget B', 'sku' => 'SKU-B']);

        $partialItem = InquiryItem::create([
            'inquiry_id' => $inquiry->id, 'product_id' => $partial->id,
            'quantity' => 10, 'unit' => 'pcs', 'sort_order' => 1,
        ]);
        $fullItem = InquiryItem::create([
            'inquiry_id' => $inquiry->id, 'product_id' => $full->id,
            'quantity' => 5, 'unit' => 'pcs', 'sort_order' => 2,
        ]);

        ProformaInvoiceItemFactory::new()->create([
            'proforma_invoice_id' => $pi->id,
            'product_id' => $partial->id,
            'quantity' => 6,
        ]);
        ProformaInvoiceItemFactory::new()->create([
            'proforma_invoice_id' => $pi->id,
            'product_id' => $full->id,
            'quantity' => 5,
        ]);

        Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $pi,
            'pageClass' => EditProformaInvoice::class,
        ])
            ->callTableAction('importFromInquiry', data: [
                'item_ids' => [$partialItem->id, $fullItem->id],
                'only_remaining' => true,
            ]);

        // Widget A gets a new line with the 4-pc balance; Widget B is skipped.
        $this->assertSame(4, (int) $pi->items()->where('product_id', $partial->id)->orderByDesc('id')->first()->quantity);
        $this->assertSame(1, $pi->items()->where('product_id', $full->id)->count());
    }

    public function test_quotation_import_options_show_model_number_preferring_client_pivot_code(): void
    {
        $client = Company::factory()->create();
        $inquiry = Inquiry::factory()->create(['company_id' => $client->id]);
        $pi = ProformaInvoice::factory()->create([
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
        ]);

        $quotation = Quotation::factory()->create([
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
        ]);

        $withPivot = Product::factory()->create([
            'name' => 'Widget A', 'sku' => 'SKU-A', 'model_number' => 'MOD-A',
        ]);
        $withPivot->companies()->attach($client->id, [
            'role' => 'client', 'external_code' => 'EXT-A',
        ]);

        // Product without client pivot → falls back to its own model_number.
        $plain = Product::factory()->create([
            'name' => 'Widget B', 'sku' => 'SKU-B', 'model_number' => 'MOD-B',
        ]);

        QuotationItem::create([
            'quotation_id' => $quotation->id, 'product_id' => $withPivot->id,
            'quantity' => 10, 'unit_cost' => 0, 'unit_price' => 1000, 'commission_rate' => 0,
        ]);
        QuotationItem::create([
            'quotation_id' => $quotation->id, 'product_id' => $plain->id,
            'quantity' => 5, 'unit_cost' => 0, 'unit_price' => 2000, 'commission_rate' => 0,
        ]);

        Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $pi,
            'pageClass' => EditProformaInvoice::class,
        ])
            ->mountTableAction('importFromQuotations')
            ->assertMountedActionModalSee('EXT-A — Widget A')
            ->assertMountedActionModalSee('MOD-B — Widget B')
            ->assertMountedActionModalDontSee('MOD-A');
    }
}
