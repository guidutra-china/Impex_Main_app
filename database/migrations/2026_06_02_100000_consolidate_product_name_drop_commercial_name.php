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
     * Two-step, lossless backfill before the column is removed:
     *   1) Where `name` is empty or auto-generated (== 'New Product' or == the
     *      product's category name), set `name` = `commercial_name`.
     *   2) For every remaining row where `commercial_name` still holds value,
     *      MERGE it into `name` so no information is lost:
     *        - name already contains commercial (ignoring case/spaces) → keep name
     *        - commercial contains name                                 → use commercial
     *        - disjoint                                                 → "name — commercial"
     */
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'commercial_name')) {
            return;
        }

        // Step 1 — conservative backfill of empty/auto-generated names.
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

        // Step 2 — lossless merge of any remaining divergent rows.
        DB::table('products')
            ->whereNotNull('commercial_name')
            ->where('commercial_name', '<>', '')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $p) {
                    $merged = $this->mergeName((string) $p->name, (string) $p->commercial_name);
                    if ($merged !== (string) $p->name) {
                        DB::table('products')->where('id', $p->id)->update(['name' => $merged]);
                    }
                }
            });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['commercial_name']);
            $table->dropColumn('commercial_name');
        });
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

    /**
     * Merge a commercial_name into the canonical name without losing information.
     */
    private function mergeName(string $name, string $commercial): string
    {
        $name = trim($name);
        $commercial = trim($commercial);

        if ($commercial === '') {
            return $name;
        }
        if ($name === '') {
            return $commercial;
        }

        $n = $this->norm($name);
        $c = $this->norm($commercial);

        if ($n === $c) {
            return $name;                       // same content, just spacing/case
        }
        if (str_contains($n, $c)) {
            return $name;                       // name already includes commercial
        }
        if (str_contains($c, $n)) {
            return $commercial;                 // commercial is the more complete one
        }

        return $name.' — '.$commercial;         // genuinely different → keep both
    }

    private function norm(string $s): string
    {
        return preg_replace('/\s+/', '', mb_strtolower($s));
    }
};
