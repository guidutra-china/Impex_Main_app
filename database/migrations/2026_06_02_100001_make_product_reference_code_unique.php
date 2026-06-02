<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Promote `reference_code` from a plain index to a UNIQUE index.
     *
     * `reference_code` is the import idempotency key (matched before name when
     * re-importing) and the variant parent linker. A unique constraint turns it
     * into a real idempotency key, preventing silent duplicate products. The
     * column stays nullable — products without a reference_code are allowed and
     * multiple NULLs are permitted by the unique index.
     *
     * PRECONDITION: duplicate non-null reference_code values must be resolved
     * before this migration runs in production, otherwise the unique index fails.
     */
    public function up(): void
    {
        // Defensive guard: abort with a clear message instead of a cryptic
        // constraint error if duplicate reference_code values still exist.
        $duplicates = DB::table('products')
            ->select('reference_code', DB::raw('COUNT(*) as c'))
            ->whereNotNull('reference_code')
            ->where('reference_code', '<>', '')
            ->groupBy('reference_code')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('reference_code')
            ->all();

        if (! empty($duplicates)) {
            throw new \RuntimeException(
                'Não é possível tornar reference_code único: existem valores duplicados ('
                .implode(', ', array_slice($duplicates, 0, 10))
                .(count($duplicates) > 10 ? ', …' : '')
                .'). Rode "php artisan products:consolidation-preflight" e resolva antes de migrar.'
            );
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['reference_code']);
            $table->unique('reference_code');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['reference_code']);
            $table->index('reference_code');
        });
    }
};
