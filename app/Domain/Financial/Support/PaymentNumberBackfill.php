<?php

namespace App\Domain\Financial\Support;

use App\Domain\Infrastructure\Enums\DocumentType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Numera os pagamentos legados (payments.number NULL) na ordem em que foram
 * criados — o mesmo número que teriam ganhado se a sequência existisse —
 * e deixa reference_sequences pronta para o próximo pagamento continuar.
 *
 * Inclui soft-deletados (número nunca é reemitido), respeita números já
 * atribuídos e nunca reduz um next_number existente. Idempotente. Query
 * builder puro: roda em MySQL (produção) e SQLite (suíte).
 */
final class PaymentNumberBackfill
{
    /** @return int pagamentos numerados */
    public static function run(): int
    {
        $type = DocumentType::PAYMENT;
        $pad = $type->padLength();

        $pending = DB::table('payments')
            ->whereNull('number')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'created_at']);

        if ($pending->isEmpty()) {
            return 0;
        }

        // Highest number already assigned per year (partial runs, manual rows).
        $maxAssigned = [];
        foreach (DB::table('payments')->whereNotNull('number')->pluck('number') as $number) {
            if (preg_match('/^'.$type->value.'-(\d{4})-(\d+)$/', (string) $number, $m)) {
                $maxAssigned[(int) $m[1]] = max($maxAssigned[(int) $m[1]] ?? 0, (int) $m[2]);
            }
        }

        $next = [];
        $changed = 0;

        foreach ($pending as $row) {
            $year = CarbonImmutable::parse($row->created_at)->year;

            if (! isset($next[$year])) {
                $sequence = DB::table('reference_sequences')
                    ->where('type', $type->value)
                    ->where('year', $year)
                    ->value('next_number');

                $next[$year] = max((int) ($sequence ?? 1), ($maxAssigned[$year] ?? 0) + 1);
            }

            DB::table('payments')->where('id', $row->id)->update([
                'number' => sprintf('%s-%d-%s', $type->value, $year, str_pad((string) $next[$year], $pad, '0', STR_PAD_LEFT)),
            ]);

            $next[$year]++;
            $changed++;
        }

        foreach ($next as $year => $nextNumber) {
            $existing = DB::table('reference_sequences')
                ->where('type', $type->value)
                ->where('year', $year)
                ->first();

            if ($existing === null) {
                DB::table('reference_sequences')->insert([
                    'type' => $type->value,
                    'year' => $year,
                    'next_number' => $nextNumber,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } elseif ((int) $existing->next_number < $nextNumber) {
                DB::table('reference_sequences')
                    ->where('id', $existing->id)
                    ->update(['next_number' => $nextNumber, 'updated_at' => now()]);
            }
        }

        return $changed;
    }
}
