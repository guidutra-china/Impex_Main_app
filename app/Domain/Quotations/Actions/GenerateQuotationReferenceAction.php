<?php

namespace App\Domain\Quotations\Actions;

use App\Domain\Quotations\Models\Quotation;
use Illuminate\Support\Facades\DB;

class GenerateQuotationReferenceAction
{
    /**
     * Gera uma referência única para a cotação dentro de uma transação com lock pessimista.
     * Faz até 3 tentativas em caso de colisão de unique constraint.
     */
    public function execute(): string
    {
        $attempts = 0;
        $maxAttempts = 3;

        while ($attempts < $maxAttempts) {
            try {
                return DB::transaction(function () {
                    $year = now()->year;
                    $prefix = "QT-{$year}-";

                    $lastRef = Quotation::withTrashed()
                        ->where('reference', 'like', $prefix . '%')
                        ->orderBy('id', 'desc')
                        ->lockForUpdate()
                        ->value('reference');

                    $nextNumber = $lastRef
                        ? (int) last(explode('-', $lastRef)) + 1
                        : 1;

                    return $prefix . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
                });
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                $attempts++;

                if ($attempts >= $maxAttempts) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Failed to generate unique quotation reference after ' . $maxAttempts . ' attempts.');
    }
}
