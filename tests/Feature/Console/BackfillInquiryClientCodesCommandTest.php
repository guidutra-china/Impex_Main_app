<?php

namespace Tests\Feature\Console;

use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Inquiries\Models\InquiryItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillInquiryClientCodesCommandTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    private Inquiry $inquiry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Company::factory()->create();
        $this->inquiry = Inquiry::factory()->create(['company_id' => $this->client->id]);
    }

    private function makeLinkedItem(string $description, Product $product): InquiryItem
    {
        return InquiryItem::create([
            'inquiry_id' => $this->inquiry->id,
            'product_id' => $product->id,
            'description' => $description,
            'quantity' => 1,
            'unit' => 'pcs',
            'sort_order' => ($this->inquiry->items()->max('sort_order') ?? 0) + 1,
        ]);
    }

    private function clientPivot(Product $product): ?CompanyProduct
    {
        return CompanyProduct::query()
            ->where('company_id', $this->client->id)
            ->where('product_id', $product->id)
            ->where('role', 'client')
            ->first();
    }

    public function test_creates_fills_and_preserves_conflicting_pivots(): void
    {
        $missing = Product::factory()->create();
        $empty = Product::factory()->create();
        $conflicting = Product::factory()->create();

        $empty->companies()->attach($this->client->id, ['role' => 'client']);
        $conflicting->companies()->attach($this->client->id, [
            'role' => 'client', 'external_code' => 'KEEP-ME',
        ]);

        $this->makeLinkedItem('DPF-LT012', $missing);
        $this->makeLinkedItem('DPF-HEX-DB- 3', $empty); // espaço deve ser normalizado
        $this->makeLinkedItem('DPF-OTHER', $conflicting);

        $this->artisan('inquiries:backfill-client-codes', [
            'inquiry' => $this->inquiry->reference,
        ])->assertSuccessful();

        $this->assertNull($this->clientPivot($missing), 'dry-run must not persist');

        $this->artisan('inquiries:backfill-client-codes', [
            'inquiry' => $this->inquiry->reference,
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertSame('DPF-LT012', $this->clientPivot($missing)->external_code);
        $this->assertSame('DPF-HEX-DB-3', $this->clientPivot($empty)->external_code);
        $this->assertSame('KEEP-ME', $this->clientPivot($conflicting)->external_code);
    }
}
