<?php

namespace Tests\Feature\Console;

use App\Domain\Catalog\Models\Product;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillPiItemDescriptionsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makePiItem(?Product $product, ?string $description, string $piStatus = 'draft'): ProformaInvoiceItem
    {
        return ProformaInvoiceItem::create([
            'proforma_invoice_id' => ProformaInvoice::factory()->create(['status' => $piStatus])->id,
            'product_id' => $product?->id,
            'description' => $description,
            'quantity' => 1,
            'unit_price' => 1000,
            'unit' => 'pcs',
        ]);
    }

    public function test_refreshes_descriptions_that_diverged_from_product_name(): void
    {
        $product = Product::factory()->create(['name' => 'New Name — Trailer Axle']);
        $stale = $this->makePiItem($product, 'Old Name');

        $matching = $this->makePiItem(
            Product::factory()->create(['name' => 'Already Correct']),
            'Already Correct',
        );

        $manual = $this->makePiItem(null, 'Manual item without product');

        $issued = $this->makePiItem(
            Product::factory()->create(['name' => 'Issued PI New Name']),
            'Issued PI Old Name',
            'sent',
        );

        $this->artisan('proforma-invoices:backfill-item-descriptions')
            ->assertSuccessful();

        $this->assertSame('New Name — Trailer Axle', $stale->fresh()->description);
        $this->assertSame('Already Correct', $matching->fresh()->description);
        $this->assertSame('Manual item without product', $manual->fresh()->description);
        $this->assertSame('Issued PI Old Name', $issued->fresh()->description);
    }

    public function test_dry_run_persists_nothing_and_pi_option_limits_scope(): void
    {
        $product = Product::factory()->create(['name' => 'New Name']);
        $stale = $this->makePiItem($product, 'Old Name');

        $this->artisan('proforma-invoices:backfill-item-descriptions', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame('Old Name', $stale->fresh()->description);

        $otherProduct = Product::factory()->create(['name' => 'Other New Name']);
        $outOfScope = $this->makePiItem($otherProduct, 'Other Old Name');

        $this->artisan('proforma-invoices:backfill-item-descriptions', [
            '--pi' => $stale->proforma_invoice_id,
        ])->assertSuccessful();

        $this->assertSame('New Name', $stale->fresh()->description);
        $this->assertSame('Other Old Name', $outOfScope->fresh()->description);
    }
}
