<?php

namespace App\Domain\Catalog\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One-time cleanup command to delete the 19 duplicate variant products
 * (IDs 29–47) that were created by a bug in the Excel import service.
 *
 * The bug caused variant rows (those with a parent_ref value) to be skipped
 * during conflict detection, so they always defaulted to 'create' even when
 * the user selected 'Update'. This command removes those duplicates and all
 * their associated data in the correct dependency order.
 *
 * Usage:
 *   php artisan products:delete-duplicates
 *   php artisan products:delete-duplicates --force   (skip confirmation prompt)
 */
class DeleteDuplicateProductsCommand extends Command
{
    protected $signature = 'products:delete-duplicates
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Delete the 19 duplicate variant products (IDs 29–47) and all their dependencies';

    /** The exact IDs of the duplicate products to be removed. */
    private const DUPLICATE_IDS = [29, 30, 31, 32, 33, 34, 35, 36, 37, 38, 39, 40, 41, 42, 43, 44, 45, 46, 47];

    public function handle(): int
    {
        $ids = self::DUPLICATE_IDS;

        // ── 1. Show what will be deleted ──────────────────────────────────────
        $this->newLine();
        $this->line('<fg=yellow>The following products are scheduled for deletion:</>');
        $this->newLine();

        $products = DB::table('products')
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get(['id', 'name', 'sku']);

        if ($products->isEmpty()) {
            $this->warn('None of the target product IDs (29–47) were found in the database.');
            $this->warn('They may have already been deleted. Nothing to do.');
            return self::SUCCESS;
        }

        $rows = $products->map(fn ($p) => [
            (string) $p->id,
            $p->sku ?? '—',
            $p->name ?? '(no name)',
        ])->toArray();

        $this->table(['ID', 'SKU', 'Name'], $rows);

        $foundIds   = $products->pluck('id')->toArray();
        $missingIds = array_diff($ids, $foundIds);

        if (! empty($missingIds)) {
            $this->warn('The following IDs were not found (already deleted or never existed): ' . implode(', ', $missingIds));
        }

        $this->newLine();
        $this->line('<fg=red>This will permanently delete ' . count($foundIds) . ' product(s) and ALL their associated data.</>');
        $this->line('Affected tables: activity_log, taggables, quotation_items (rows deleted),');
        $this->line('  supplier_quotation_items (nulled), purchase_order_items (nulled),');
        $this->line('  inquiry_items (nulled), proforma_invoice_items (nulled),');
        $this->line('  company_product_documents, company_product, product_attribute_values,');
        $this->line('  product_costings, product_packagings, product_specifications,');
        $this->line('  child products (parent_id set to null), and finally the products themselves.');
        $this->newLine();

        // ── 2. Confirm ────────────────────────────────────────────────────────
        if (! $this->option('force')) {
            if (! $this->confirm('Are you sure you want to proceed? This action CANNOT be undone.', false)) {
                $this->info('Aborted. No changes were made.');
                return self::SUCCESS;
            }
        }

        // ── 3. Delete in dependency order inside a transaction ────────────────
        $this->newLine();
        $this->info('Starting deletion inside a database transaction…');

        try {
            DB::transaction(function () use ($foundIds): void {
                $productType = 'App\\Domain\\Catalog\\Models\\Product';

                // 3a. Activity log entries
                $count = DB::table('activity_log')
                    ->where('subject_type', $productType)
                    ->whereIn('subject_id', $foundIds)
                    ->delete();
                $this->line("  ✓ activity_log: {$count} row(s) deleted");

                // 3b. Taggables
                $count = DB::table('taggables')
                    ->where('taggable_type', $productType)
                    ->whereIn('taggable_id', $foundIds)
                    ->delete();
                $this->line("  ✓ taggables: {$count} row(s) deleted");

                // 3c. quotation_items — product_id is NOT nullable (no nullOnDelete),
                //     so rows that reference these products must be deleted outright.
                //     quotation_item_suppliers will cascade automatically.
                $count = DB::table('quotation_items')
                    ->whereIn('product_id', $foundIds)
                    ->delete();
                $this->line("  ✓ quotation_items: {$count} row(s) deleted (quotation_item_suppliers cascade)");

                // 3d. supplier_quotation_items — nullable, set to null
                $count = DB::table('supplier_quotation_items')
                    ->whereIn('product_id', $foundIds)
                    ->update(['product_id' => null]);
                $this->line("  ✓ supplier_quotation_items: {$count} row(s) nulled");

                // 3e. purchase_order_items — nullable, set to null
                $count = DB::table('purchase_order_items')
                    ->whereIn('product_id', $foundIds)
                    ->update(['product_id' => null]);
                $this->line("  ✓ purchase_order_items: {$count} row(s) nulled");

                // 3f. inquiry_items — nullable, set to null
                $count = DB::table('inquiry_items')
                    ->whereIn('product_id', $foundIds)
                    ->update(['product_id' => null]);
                $this->line("  ✓ inquiry_items: {$count} row(s) nulled");

                // 3g. proforma_invoice_items — nullable, set to null
                $count = DB::table('proforma_invoice_items')
                    ->whereIn('product_id', $foundIds)
                    ->update(['product_id' => null]);
                $this->line("  ✓ proforma_invoice_items: {$count} row(s) nulled");

                // 3h. company_product_documents (via company_product IDs)
                $companyProductIds = DB::table('company_product')
                    ->whereIn('product_id', $foundIds)
                    ->pluck('id')
                    ->toArray();

                if (! empty($companyProductIds)) {
                    $count = DB::table('company_product_documents')
                        ->whereIn('company_product_id', $companyProductIds)
                        ->delete();
                    $this->line("  ✓ company_product_documents: {$count} row(s) deleted");
                } else {
                    $this->line('  ✓ company_product_documents: 0 row(s) deleted (no company_product rows found)');
                }

                // 3i. company_product pivot rows
                $count = DB::table('company_product')
                    ->whereIn('product_id', $foundIds)
                    ->delete();
                $this->line("  ✓ company_product: {$count} row(s) deleted");

                // 3j. product_attribute_values
                $count = DB::table('product_attribute_values')
                    ->whereIn('product_id', $foundIds)
                    ->delete();
                $this->line("  ✓ product_attribute_values: {$count} row(s) deleted");

                // 3k. product_costings
                $count = DB::table('product_costings')
                    ->whereIn('product_id', $foundIds)
                    ->delete();
                $this->line("  ✓ product_costings: {$count} row(s) deleted");

                // 3l. product_packagings
                $count = DB::table('product_packagings')
                    ->whereIn('product_id', $foundIds)
                    ->delete();
                $this->line("  ✓ product_packagings: {$count} row(s) deleted");

                // 3m. product_specifications
                $count = DB::table('product_specifications')
                    ->whereIn('product_id', $foundIds)
                    ->delete();
                $this->line("  ✓ product_specifications: {$count} row(s) deleted");

                // 3n. Detach any child products that point to these as parent
                $count = DB::table('products')
                    ->whereIn('parent_id', $foundIds)
                    ->update(['parent_id' => null]);
                $this->line("  ✓ products (child parent_id nulled): {$count} row(s) updated");

                // 3o. Finally delete the duplicate products themselves
                $count = DB::table('products')
                    ->whereIn('id', $foundIds)
                    ->delete();
                $this->line("  ✓ products: {$count} row(s) deleted");
            });

            $this->newLine();
            $this->info('✅ All ' . count($foundIds) . ' duplicate product(s) and their dependencies were successfully deleted.');
            $this->newLine();

            return self::SUCCESS;

        } catch (Throwable $e) {
            $this->newLine();
            $this->error('❌ An error occurred. The transaction was rolled back. No data was changed.');
            $this->error('Error: ' . $e->getMessage());
            $this->newLine();

            return self::FAILURE;
        }
    }
}
