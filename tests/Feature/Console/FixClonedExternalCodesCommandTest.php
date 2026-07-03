<?php

namespace Tests\Feature\Console;

use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards catalog:fix-cloned-external-codes — the production repair for pivots
 * polluted by the product Clone action (which used to copy external_* fields
 * to every replica). The oldest pivot of each duplicate group is presumed the
 * intentional original and must be kept.
 */
class FixClonedExternalCodesCommandTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Company::factory()->create(['name' => 'TRADETEK']);
    }

    private function makePivot(string $productName, array $attributes, string $createdAt, ?Company $company = null): CompanyProduct
    {
        $product = Product::factory()->create(['name' => $productName]);

        $pivot = CompanyProduct::create(array_merge([
            'company_id' => ($company ?? $this->client)->id,
            'product_id' => $product->id,
            'role' => 'client',
        ], $attributes));

        $pivot->created_at = $createdAt;
        $pivot->save();

        return $pivot;
    }

    public function test_dry_run_reports_duplicates_without_changing_anything(): void
    {
        $original = $this->makePivot('Lens 30', ['external_code' => '30-8H1'], '2026-04-11 10:00:00');
        $clone = $this->makePivot('Lens 15', ['external_code' => '30-8H1'], '2026-06-05 18:01:19');

        $this->artisan('catalog:fix-cloned-external-codes')
            ->expectsOutputToContain('DRY-RUN')
            ->expectsOutputToContain('1 pivot field(s) would be cleared')
            ->assertExitCode(0);

        $this->assertSame('30-8H1', $original->fresh()->external_code);
        $this->assertSame('30-8H1', $clone->fresh()->external_code);
    }

    public function test_apply_clears_duplicates_keeping_the_oldest_pivot(): void
    {
        $original = $this->makePivot('Lens 30', ['external_code' => '30-8H1'], '2026-04-11 10:00:00');
        $cloneA = $this->makePivot('Lens 15', ['external_code' => '30-8H1'], '2026-06-05 18:01:19');
        $cloneB = $this->makePivot('Lens 60', ['external_code' => '30-8H1'], '2026-06-05 18:05:56');
        $unique = $this->makePivot('White Label', ['external_code' => 'White Label'], '2026-04-02 09:00:00');

        $this->artisan('catalog:fix-cloned-external-codes --apply')
            ->expectsOutputToContain('2 pivot field(s) cleared')
            ->assertExitCode(0);

        $this->assertSame('30-8H1', $original->fresh()->external_code, 'oldest pivot keeps its code');
        $this->assertNull($cloneA->fresh()->external_code);
        $this->assertNull($cloneB->fresh()->external_code);
        $this->assertSame('White Label', $unique->fresh()->external_code, 'non-duplicated codes are untouched');
    }

    public function test_external_name_duplicates_are_cleared_independently_of_code(): void
    {
        $original = $this->makePivot('PCB 24', ['external_name' => 'PCB LED 24LEDS', 'external_code' => 'PCB-A'], '2026-05-01 10:00:00');
        $clone = $this->makePivot('PCB 48', ['external_name' => 'PCB LED 24LEDS', 'external_code' => 'PCB-B'], '2026-06-05 20:15:35');

        $this->artisan('catalog:fix-cloned-external-codes --apply')->assertExitCode(0);

        $this->assertSame('PCB LED 24LEDS', $original->fresh()->external_name);
        $this->assertNull($clone->fresh()->external_name);

        // Codes differ, so both survive.
        $this->assertSame('PCB-A', $original->fresh()->external_code);
        $this->assertSame('PCB-B', $clone->fresh()->external_code);
    }

    public function test_same_code_for_different_companies_is_not_treated_as_duplicate(): void
    {
        $otherClient = Company::factory()->create(['name' => 'Other Co']);

        $mine = $this->makePivot('Lens A', ['external_code' => 'SHARED'], '2026-04-01 10:00:00');
        $theirs = $this->makePivot('Lens B', ['external_code' => 'SHARED'], '2026-06-01 10:00:00', $otherClient);

        $this->artisan('catalog:fix-cloned-external-codes --apply')->assertExitCode(0);

        $this->assertSame('SHARED', $mine->fresh()->external_code);
        $this->assertSame('SHARED', $theirs->fresh()->external_code);
    }

    public function test_company_option_limits_the_scan(): void
    {
        $otherClient = Company::factory()->create(['name' => 'Other Co']);

        $this->makePivot('Lens A', ['external_code' => 'DUP-1'], '2026-04-01 10:00:00');
        $mineClone = $this->makePivot('Lens B', ['external_code' => 'DUP-1'], '2026-06-01 10:00:00');

        $this->makePivot('PCB A', ['external_code' => 'DUP-2'], '2026-04-01 10:00:00', $otherClient);
        $otherClone = $this->makePivot('PCB B', ['external_code' => 'DUP-2'], '2026-06-01 10:00:00', $otherClient);

        $this->artisan("catalog:fix-cloned-external-codes --apply --company={$this->client->id}")
            ->assertExitCode(0);

        $this->assertNull($mineClone->fresh()->external_code);
        $this->assertSame('DUP-2', $otherClone->fresh()->external_code, 'other company untouched when --company is set');
    }

    public function test_invalid_field_option_fails(): void
    {
        $this->artisan('catalog:fix-cloned-external-codes --field=bogus')
            ->assertExitCode(1);
    }

    public function test_same_batch_groups_are_ignored_by_default_but_included_with_flag(): void
    {
        // Bulk import writes the same generic name on many rows in one request —
        // intentional, not a clone artifact (a clone is always newer than its original).
        $batchA = $this->makePivot('Street Light 40W', ['external_name' => 'LED ECO Street Light'], '2026-03-05 14:10:00');
        $batchB = $this->makePivot('Street Light 50W', ['external_name' => 'LED ECO Street Light'], '2026-03-05 14:10:03');

        $this->artisan('catalog:fix-cloned-external-codes --apply')
            ->expectsOutputToContain('0 pivot field(s) cleared')
            ->assertExitCode(0);

        $this->assertSame('LED ECO Street Light', $batchA->fresh()->external_name);
        $this->assertSame('LED ECO Street Light', $batchB->fresh()->external_name);

        $this->artisan('catalog:fix-cloned-external-codes --apply --include-same-batch')
            ->expectsOutputToContain('1 pivot field(s) cleared')
            ->assertExitCode(0);

        $this->assertSame('LED ECO Street Light', $batchA->fresh()->external_name, 'oldest still kept');
        $this->assertNull($batchB->fresh()->external_name);
    }

    public function test_later_bulk_import_batches_are_kept_while_lone_clones_are_cleared(): void
    {
        // First import (the original).
        $first = $this->makePivot('Light 26W', ['external_name' => 'LED ECO Street Light'], '2026-03-05 10:45:00');

        // Second bulk import hours later: 3+ rows created together = intentional.
        $batch = collect([
            $this->makePivot('Light 110W', ['external_name' => 'LED ECO Street Light'], '2026-03-05 14:10:00'),
            $this->makePivot('Light 120W', ['external_name' => 'LED ECO Street Light'], '2026-03-05 14:10:01'),
            $this->makePivot('Light 130W', ['external_name' => 'LED ECO Street Light'], '2026-03-05 14:10:02'),
        ]);

        // Lone row created weeks later = clone artifact.
        $clone = $this->makePivot('Light 170W', ['external_name' => 'LED ECO Street Light'], '2026-03-27 10:46:00');

        $this->artisan('catalog:fix-cloned-external-codes --apply')
            ->expectsOutputToContain('1 pivot field(s) cleared')
            ->assertExitCode(0);

        $this->assertSame('LED ECO Street Light', $first->fresh()->external_name);
        $batch->each(fn ($p) => $this->assertSame('LED ECO Street Light', $p->fresh()->external_name, 'bulk batch rows are kept'));
        $this->assertNull($clone->fresh()->external_name);
    }

    public function test_clones_created_seconds_apart_are_still_cleared(): void
    {
        // Fast one-by-one cloning through the UI (~30s per product) must not be
        // mistaken for a bulk import batch — regression for the SH-2026-00028 lenses.
        $original = $this->makePivot('Lens 30', ['external_code' => '30-8H1'], '2026-04-11 15:50:17');
        $cloneA = $this->makePivot('Lens 15', ['external_code' => '30-8H1'], '2026-06-05 18:05:27');
        $cloneB = $this->makePivot('Lens 60', ['external_code' => '30-8H1'], '2026-06-05 18:05:56');
        $cloneC = $this->makePivot('Lens 90', ['external_code' => '30-8H1'], '2026-06-05 18:06:24');

        $this->artisan('catalog:fix-cloned-external-codes --apply')
            ->expectsOutputToContain('3 pivot field(s) cleared')
            ->assertExitCode(0);

        $this->assertSame('30-8H1', $original->fresh()->external_code);
        $this->assertNull($cloneA->fresh()->external_code);
        $this->assertNull($cloneB->fresh()->external_code);
        $this->assertNull($cloneC->fresh()->external_code);
    }

    public function test_skip_option_preserves_specific_pivots(): void
    {
        $this->makePivot('Lens 30', ['external_code' => '30-8H1'], '2026-04-11 10:00:00');
        $skipped = $this->makePivot('Lens 15', ['external_code' => '30-8H1'], '2026-06-05 18:01:19');
        $cleared = $this->makePivot('Lens 60', ['external_code' => '30-8H1'], '2026-06-05 18:05:56');

        $this->artisan("catalog:fix-cloned-external-codes --apply --skip={$skipped->id}")
            ->expectsOutputToContain('1 pivot field(s) cleared')
            ->assertExitCode(0);

        $this->assertSame('30-8H1', $skipped->fresh()->external_code);
        $this->assertNull($cleared->fresh()->external_code);
    }
}
