<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DN/CN passam a numerar via reference_sequences (lockForUpdate) em vez do scan
 * max(sufixo)+1. Semeia o contador de cada (tipo, ano) a partir das notas já
 * emitidas — incluindo soft-deletadas — para que a próxima referência continue
 * do maior número existente. Idempotente: nunca reduz um next_number já maior.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->seed('DN', 'debit_notes');
        $this->seed('CN', 'credit_notes');
    }

    public function down(): void
    {
        // Keep the seeded counters — dropping them could reissue numbers.
    }

    private function seed(string $type, string $table): void
    {
        $references = DB::table($table)->pluck('reference');

        $maxPerYear = [];
        foreach ($references as $reference) {
            if (! preg_match('/^'.$type.'-(\d{4})-(\d+)$/', (string) $reference, $matches)) {
                continue;
            }

            $year = (int) $matches[1];
            $number = (int) $matches[2];
            $maxPerYear[$year] = max($maxPerYear[$year] ?? 0, $number);
        }

        foreach ($maxPerYear as $year => $max) {
            $existing = DB::table('reference_sequences')
                ->where('type', $type)
                ->where('year', $year)
                ->first();

            if ($existing === null) {
                DB::table('reference_sequences')->insert([
                    'type' => $type,
                    'year' => $year,
                    'next_number' => $max + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } elseif ((int) $existing->next_number <= $max) {
                DB::table('reference_sequences')
                    ->where('id', $existing->id)
                    ->update(['next_number' => $max + 1, 'updated_at' => now()]);
            }
        }
    }
};
