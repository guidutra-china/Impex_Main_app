<?php

namespace Tests\Feature\Console;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Inquiries\Models\InquiryItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LinkInquiryItemsToProductsCommandTest extends TestCase
{
    use RefreshDatabase;

    private Inquiry $inquiry;

    protected function setUp(): void
    {
        parent::setUp();

        $client = Company::factory()->create();
        $this->inquiry = Inquiry::factory()->create(['company_id' => $client->id]);
    }

    private function makeItem(string $description): InquiryItem
    {
        return InquiryItem::create([
            'inquiry_id' => $this->inquiry->id,
            'product_id' => null,
            'description' => $description,
            'quantity' => 1,
            'unit' => 'pcs',
            'sort_order' => ($this->inquiry->items()->max('sort_order') ?? 0) + 1,
        ]);
    }

    public function test_links_by_stripping_client_prefix_and_normalizing_spaces(): void
    {
        $product = Product::factory()->create(['sku' => 'GYM-00029', 'model_number' => 'LT012']);
        $spaced = Product::factory()->create(['sku' => 'DB-00001', 'model_number' => 'HEX-DB-1']);

        $byModel = $this->makeItem('DPF-LT012');
        $bySpacedModel = $this->makeItem('DPF-HEX-DB- 1');

        $this->artisan('inquiries:link-items-to-products', [
            'inquiry' => $this->inquiry->reference,
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertSame($product->id, $byModel->fresh()->product_id);
        $this->assertSame($spaced->id, $bySpacedModel->fresh()->product_id);
    }

    public function test_links_by_stripping_order_suffix_and_falling_back_to_specifications(): void
    {
        $bySuffix = Product::factory()->create(['sku' => 'AGR-00186', 'model_number' => 'TS847']);
        $bySpecs = Product::factory()->create(['sku' => 'AGR-00160', 'model_number' => 'CA550K19 6X6 C/102ELOS 02EM 04RED']);

        // Sufixo de ordem "-31" anexado ao código pela extração.
        $suffixItem = $this->makeItem('MRG-TS847-31');

        // Descrição truncada em 20 chars; código completo só nas specs.
        $specsItem = InquiryItem::create([
            'inquiry_id' => $this->inquiry->id,
            'description' => 'MRG-CA550K19 6X6 C/1-5',
            'specifications' => 'CA550K19 6X6 C/102ELOS 02EM 04RED',
            'quantity' => 1,
            'unit' => 'pcs',
            'sort_order' => ($this->inquiry->items()->max('sort_order') ?? 0) + 1,
        ]);

        $this->artisan('inquiries:link-items-to-products', [
            'inquiry' => $this->inquiry->reference,
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertSame($bySuffix->id, $suffixItem->fresh()->product_id);
        $this->assertSame($bySpecs->id, $specsItem->fresh()->product_id);
    }

    public function test_dry_run_writes_nothing_and_ambiguous_or_unmatched_items_are_left_alone(): void
    {
        Product::factory()->create(['sku' => 'A-00001', 'model_number' => 'X19']);
        Product::factory()->create(['sku' => 'B-00001', 'reference_code' => 'X19']);
        Product::factory()->create(['sku' => 'C-00001', 'model_number' => 'LT012']);

        $dryRun = $this->makeItem('DPF-LT012');
        $ambiguous = $this->makeItem('DPF-X19');
        $unmatched = $this->makeItem('DPF-NOPE-999');

        $this->artisan('inquiries:link-items-to-products', [
            'inquiry' => $this->inquiry->reference,
        ])->assertSuccessful();

        $this->assertNull($dryRun->fresh()->product_id, 'dry-run must not persist');

        $this->artisan('inquiries:link-items-to-products', [
            'inquiry' => $this->inquiry->reference,
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertNotNull($dryRun->fresh()->product_id);
        $this->assertNull($ambiguous->fresh()->product_id);
        $this->assertNull($unmatched->fresh()->product_id);
    }
}
