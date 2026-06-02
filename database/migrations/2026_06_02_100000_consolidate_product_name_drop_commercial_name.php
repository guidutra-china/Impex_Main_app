<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Consolidate product naming into a single `name` field and drop `commercial_name`.
     *
     * Backfill rule (conservative): only overwrite `name` with `commercial_name`
     * when the current `name` is empty or auto-generated (equal to the legacy
     * 'New Product' fallback or to the product's category name). Rows where both
     * `name` and `commercial_name` hold distinct, meaningful values are expected
     * to have been reconciled manually before running this migration.
     */
    public function up(): void
    {
        if (Schema::hasColumn('products', 'commercial_name')) {
            // Portable correlated-subquery UPDATE (works on MySQL and SQLite).
            DB::update(<<<'SQL'
                UPDATE products
                SET name = commercial_name
                WHERE commercial_name IS NOT NULL
                  AND commercial_name <> ''
                  AND (
                        name IS NULL
                        OR name = ''
                        OR name = 'New Product'
                        OR name = (SELECT c.name FROM categories c WHERE c.id = products.category_id)
                      )
            SQL);

            Schema::table('products', function (Blueprint $table) {
                $table->dropIndex(['commercial_name']);
                $table->dropColumn('commercial_name');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('products', 'commercial_name')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('commercial_name')->nullable()->after('name');
                $table->index('commercial_name');
            });
        }
    }
};
