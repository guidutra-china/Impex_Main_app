<?php

namespace App\Domain\Infrastructure\Actions;

use App\Domain\Infrastructure\Enums\DocumentType;
use App\Domain\Infrastructure\Models\ReferenceSequence;
use Illuminate\Support\Facades\DB;

class GenerateReferenceAction
{
    public function execute(DocumentType $type, ?int $year = null): string
    {
        $year = $year ?? now()->year;

        return DB::transaction(function () use ($type, $year) {
            $sequence = ReferenceSequence::query()
                ->where('type', $type->value)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if (! $sequence) {
                $sequence = ReferenceSequence::create([
                    'type' => $type->value,
                    'year' => $year,
                    'next_number' => 1,
                ]);

                $sequence->lockForUpdate()->find($sequence->id);
            }

            $number = $sequence->next_number;

            $sequence->increment('next_number');

            return sprintf(
                '%s-%d-%s',
                $type->value,
                $year,
                str_pad($number, $type->padLength(), '0', STR_PAD_LEFT)
            );
        });
    }

    public function preview(DocumentType $type, ?int $year = null): string
    {
        $year = $year ?? now()->year;

        $sequence = ReferenceSequence::query()
            ->where('type', $type->value)
            ->where('year', $year)
            ->first();

        $number = $sequence ? $sequence->next_number : 1;

        return sprintf(
            '%s-%d-%s',
            $type->value,
            $year,
            str_pad($number, $type->padLength(), '0', STR_PAD_LEFT)
        );
    }
}
