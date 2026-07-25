<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\AI\Import\Models\ProductImportAlias;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Inquiries\Models\InquiryItem;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use App\Domain\SupplierQuotations\Models\SupplierQuotationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillProductImportAliasesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfills_from_sq_and_inquiry_items_latest_wins(): void
    {
        $supplier = Company::factory()->create();
        $client = Company::factory()->create();
        $old = Product::factory()->create();
        $new = Product::factory()->create();

        $sq = SupplierQuotation::factory()->create(['company_id' => $supplier->id]);
        // Mesma descrição duas vezes — o item mais recente vence.
        SupplierQuotationItem::factory()->create([
            'supplier_quotation_id' => $sq->id, 'product_id' => $old->id,
            'description' => 'Hex dumbbell 5kg', 'created_at' => now()->subDay(),
        ]);
        SupplierQuotationItem::factory()->create([
            'supplier_quotation_id' => $sq->id, 'product_id' => $new->id,
            'description' => 'Hex Dumbbell — 5kg', 'created_at' => now(),
        ]);
        // Sem produto: ignorado.
        SupplierQuotationItem::factory()->create([
            'supplier_quotation_id' => $sq->id, 'product_id' => null, 'description' => 'Orfão',
        ]);
        // Descrição curta demais (normaliza para 1 char): ignorada, mesmo com produto.
        SupplierQuotationItem::factory()->create([
            'supplier_quotation_id' => $sq->id, 'product_id' => $new->id, 'description' => '5',
        ]);

        $inquiry = Inquiry::factory()->create(['company_id' => $client->id]);
        InquiryItem::factory()->create([
            'inquiry_id' => $inquiry->id, 'product_id' => $old->id, 'description' => 'Anilha 10kg',
        ]);

        // Dry-run: nada gravado, mas a contagem de processados/ignorados já bate com o --apply.
        $this->artisan('imports:backfill-product-aliases')
            ->expectsOutputToContain('3 aliases processados, 1 ignorados.')
            ->assertSuccessful();
        $this->assertDatabaseCount('product_import_aliases', 0);

        // Apply.
        $this->artisan('imports:backfill-product-aliases', ['--apply' => true])
            ->expectsOutputToContain('3 aliases processados, 1 ignorados.')
            ->assertSuccessful();

        $this->assertDatabaseCount('product_import_aliases', 2);
        $this->assertSame($new->id, ProductImportAlias::where('company_id', $supplier->id)->sole()->product_id);
        $this->assertSame($old->id, ProductImportAlias::where('company_id', $client->id)->sole()->product_id);
        $this->assertSame('backfill', ProductImportAlias::first()->source);
    }

    public function test_mirror_inquiry_items_from_combined_flow_are_excluded(): void
    {
        $supplier = Company::factory()->create();
        $client = Company::factory()->create();
        $product = Product::factory()->create();

        $inquiry = Inquiry::factory()->create(['company_id' => $client->id]);
        $mirrorItem = InquiryItem::factory()->create([
            'inquiry_id' => $inquiry->id, 'product_id' => $product->id, 'description' => 'Supplier phrasing 5kg',
        ]);

        // O item de SQ referencia o inquiry item — fluxo combinado (espelho).
        $sq = SupplierQuotation::factory()->create(['company_id' => $supplier->id, 'inquiry_id' => $inquiry->id]);
        SupplierQuotationItem::factory()->create([
            'supplier_quotation_id' => $sq->id, 'product_id' => $product->id,
            'description' => 'Supplier phrasing 5kg', 'inquiry_item_id' => $mirrorItem->id,
        ]);

        $this->artisan('imports:backfill-product-aliases', ['--apply' => true])->assertSuccessful();

        // Alias do fornecedor: sim. Alias do cliente (espelho): não.
        $this->assertDatabaseCount('product_import_aliases', 1);
        $this->assertSame($supplier->id, ProductImportAlias::sole()->company_id);
    }
}
