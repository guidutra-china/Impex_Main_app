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

    public function test_pi_option_accepts_reference_and_bypasses_status_filter(): void
    {
        $issued = $this->makePiItem(
            Product::factory()->create(['name' => 'Issued New Name']),
            'Issued Old Name',
            'confirmed',
        );

        $otherIssued = $this->makePiItem(
            Product::factory()->create(['name' => 'Untouched New Name']),
            'Untouched Old Name',
            'confirmed',
        );

        $reference = $issued->proformaInvoice->reference;

        $this->artisan('proforma-invoices:backfill-item-descriptions', ['--pi' => $reference])
            ->assertSuccessful();

        $this->assertSame('Issued New Name', $issued->fresh()->description);
        $this->assertSame('Untouched Old Name', $otherIssued->fresh()->description);
    }

    public function test_all_statuses_option_includes_issued_pis(): void
    {
        $draft = $this->makePiItem(
            Product::factory()->create(['name' => 'Draft New Name']),
            'Draft Old Name',
        );

        $issued = $this->makePiItem(
            Product::factory()->create(['name' => 'Sent New Name']),
            'Sent Old Name',
            'sent',
        );

        $this->artisan('proforma-invoices:backfill-item-descriptions', ['--all-statuses' => true])
            ->assertSuccessful();

        $this->assertSame('Draft New Name', $draft->fresh()->description);
        $this->assertSame('Sent New Name', $issued->fresh()->description);
    }

    public function test_unknown_pi_fails_without_touching_anything(): void
    {
        $stale = $this->makePiItem(
            Product::factory()->create(['name' => 'New Name']),
            'Old Name',
        );

        $this->artisan('proforma-invoices:backfill-item-descriptions', ['--pi' => 'PI-9999-99999'])
            ->assertFailed();

        $this->assertSame('Old Name', $stale->fresh()->description);
    }
}
