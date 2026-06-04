<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Backfill existing business cards into the new company_photos gallery as
        // the primary (cover) photo. Use the query builder so soft-delete scopes
        // and future model changes do not affect the migration.
        if (Schema::hasColumn('companies', 'business_card_path')) {
            $now = now();

            DB::table('companies')
                ->whereNotNull('business_card_path')
                ->orderBy('id')
                ->chunkById(200, function ($companies) use ($now) {
                    $rows = [];

                    foreach ($companies as $company) {
                        $rows[] = [
                            'company_id' => $company->id,
                            'disk' => $company->business_card_disk ?: 'public',
                            'path' => $company->business_card_path,
                            'sort_order' => 0,
                            'is_primary' => true,
                            'original_name' => null,
                            'size' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }

                    if ($rows !== []) {
                        DB::table('company_photos')->insert($rows);
                    }
                });

            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn(['business_card_path', 'business_card_disk']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('business_card_path')->nullable()->after('notes');
            $table->string('business_card_disk')->default('public')->nullable()->after('business_card_path');
        });

        // Best-effort restore: map each company's primary photo back to the column.
        DB::table('company_photos')
            ->where('is_primary', true)
            ->orderBy('company_id')
            ->chunkById(200, function ($photos) {
                foreach ($photos as $photo) {
                    DB::table('companies')
                        ->where('id', $photo->company_id)
                        ->update([
                            'business_card_path' => $photo->path,
                            'business_card_disk' => $photo->disk,
                        ]);
                }
            });
    }
};
