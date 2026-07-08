<?php

namespace Tests\Feature\Console;

use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Inquiries\Models\InquiryItem;
use App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use App\Domain\SupplierQuotations\Models\SupplierQuotationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DedupeRejectedSqProductsCommandTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    private Company $supplier;

    private Inquiry $inquiry;

    private SupplierQuotation $selectedSq;

    private SupplierQuotation $rejectedSq;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Company::factory()->create();
        $this->supplier = Company::factory()->create();
        $this->inquiry = Inquiry::factory()->create(['company_id' => $this->client->id]);

        $this->selectedSq = $this->makeSq('SQ-SEL', SupplierQuotationStatus::SELECTED);
        $this->rejectedSq = $this->makeSq('SQ-REJ', SupplierQuotationStatus::REJECTED);
    }

    private function makeSq(string $reference, SupplierQuotationStatus $status): SupplierQuotation
    {
        return SupplierQuotation::create([
            'reference' => $reference,
            'inquiry_id' => $this->inquiry->id,
            'company_id' => $this->supplier->id,
            'currency_code' => 'USD',
            'status' => $status,
        ]);
    }

    private function makeSqItem(SupplierQuotation $sq, Product $product): SupplierQuotationItem
    {
        return SupplierQuotationItem::create([
            'supplier_quotation_id' => $sq->id,
            'product_id' => $product->id,
            'description' => $product->name,
            'unit_cost' => 1000,
            'quantity' => 1,
            'unit' => 'pcs',
        ]);
    }

    public function test_merges_duplicate_into_canonical_and_soft_deletes_it(): void
    {
        $canonical = Product::factory()->create(['name' => 'Dumbbell 15kg', 'sku' => 'DBE-00020']);
        $duplicate = Product::factory()->create(['name' => 'Dumbbell 15kg', 'sku' => 'DBE-00003']);

        $this->makeSqItem($this->selectedSq, $canonical);
        $rejectedItem = $this->makeSqItem($this->rejectedSq, $duplicate);

        // Ambos têm pivot de fornecedor; o do duplicado está vazio → deve ser apagado.
        foreach ([$canonical, $duplicate] as $product) {
            $product->companies()->attach($this->supplier->id, ['role' => 'supplier']);
        }

        // Pivot de cliente só no duplicado → deve ser reapontado para o canônico.
        $duplicate->companies()->attach($this->client->id, [
            'role' => 'client', 'external_code' => 'DPF-RND-DB-15',
        ]);

        $inquiryItem = InquiryItem::create([
            'inquiry_id' => $this->inquiry->id, 'product_id' => $duplicate->id,
            'description' => 'DPF-RND-DB- 15', 'quantity' => 60, 'unit' => 'pcs', 'sort_order' => 1,
        ]);

        $this->artisan('products:dedupe-rejected-sq-duplicates', [
            'inquiry' => $this->inquiry->reference,
        ])->assertSuccessful();

        $this->assertNull($duplicate->fresh()->deleted_at, 'dry-run must not persist');

        $this->artisan('products:dedupe-rejected-sq-duplicates', [
            'inquiry' => $this->inquiry->reference,
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertSoftDeleted('products', ['id' => $duplicate->id]);
        $this->assertSame($canonical->id, $rejectedItem->fresh()->product_id);
        $this->assertSame($canonical->id, $inquiryItem->fresh()->product_id);

        $this->assertSame(0, CompanyProduct::query()->where('product_id', $duplicate->id)->count());
        $this->assertSame(
            'DPF-RND-DB-15',
            CompanyProduct::query()
                ->where('product_id', $canonical->id)
                ->where('company_id', $this->client->id)
                ->where('role', 'client')
                ->value('external_code'),
        );
    }

    public function test_skips_duplicates_with_own_data_or_reused_by_live_sqs(): void
    {
        // Duplicado com variante (products.parent_id) → dados próprios, pulado.
        $canonical = Product::factory()->create(['name' => 'Squat Rack', 'sku' => 'RCK-00010']);
        $withVariant = Product::factory()->create(['name' => 'Squat Rack', 'sku' => 'RCK-00002']);
        Product::factory()->create(['name' => 'Squat Rack Blue', 'parent_id' => $withVariant->id]);

        $this->makeSqItem($this->selectedSq, $canonical);
        $this->makeSqItem($this->rejectedSq, $withVariant);

        // Produto presente na SQ rejeitada E na vigente → não é duplicado.
        $reused = Product::factory()->create(['name' => 'Flat Bench', 'sku' => 'GYM-00100']);
        $this->makeSqItem($this->selectedSq, $reused);
        $this->makeSqItem($this->rejectedSq, $reused);

        $this->artisan('products:dedupe-rejected-sq-duplicates', [
            'inquiry' => $this->inquiry->reference,
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertNull($withVariant->fresh()->deleted_at);
        $this->assertNull($reused->fresh()->deleted_at);
    }
}
