<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill the gallery from the existing single avatar column.
     * Idempotent: skips products that already have an image row.
     */
    public function up(): void
    {
        DB::table('products')
            ->whereNotNull('avatar')
            ->where('avatar', '!=', '')
            ->orderBy('id')
            ->each(function ($product) {
                $alreadyHasImages = DB::table('product_images')
                    ->where('product_id', $product->id)
                    ->exists();

                if ($alreadyHasImages) {
                    return;
                }

                DB::table('product_images')->insert([
                    'product_id' => $product->id,
                    'disk' => 'public',
                    'path' => $product->avatar,
                    'sort_order' => 0,
                    'is_primary' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    /**
     * Only remove rows that exactly mirror the product avatar, so we never
     * delete gallery images added after the backfill.
     */
    public function down(): void
    {
        DB::table('product_images as pi')
            ->join('products as p', 'p.id', '=', 'pi.product_id')
            ->whereColumn('pi.path', 'p.avatar')
            ->where('pi.is_primary', true)
            ->where('pi.sort_order', 0)
            ->delete();
    }
};
