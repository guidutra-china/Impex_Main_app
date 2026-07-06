<?php

namespace App\Domain\Logistics\Actions;

use App\Domain\Logistics\Models\Shipment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RenumberCartonsAction
{
    /**
     * Renumber all cartons of a shipment to a contiguous BOX-001..BOX-NNN range,
     * following current sort_order (then id as tiebreaker). Also resets sort_order
     * to 1..N for visual consistency.
     *
     * Uses a two-pass rename to satisfy the UNIQUE(shipment_id, label) constraint:
     * a direct swap like BOX-005 → BOX-003 would collide with the existing BOX-003
     * before that one gets a chance to become BOX-002. Stage 1 moves everything to
     * a temporary label, stage 2 writes the final sequential labels.
     */
    public function execute(Shipment $shipment): int
    {
        return DB::transaction(function () use ($shipment) {
            $cartons = $shipment->cartons()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id']);

            if ($cartons->isEmpty()) {
                return 0;
            }

            // Both stages run as chunked CASE updates (portable SQL, no string
            // functions) — renumbering thousands of cartons must not issue 2×N queries.
            $cartons->values()->chunk(500)->each(function (Collection $chunk) {
                $labelCase = 'CASE id ';
                $ids = [];

                foreach ($chunk as $carton) {
                    $labelCase .= "WHEN {$carton->id} THEN '__TMP-{$carton->id}' ";
                    $ids[] = $carton->id;
                }

                DB::update(
                    'UPDATE cartons SET label = '.$labelCase.'END WHERE id IN ('.implode(',', $ids).')',
                );
            });

            $cartons->values()->chunk(500)->each(function (Collection $chunk, int $chunkIndex) {
                $labelCase = 'CASE id ';
                $sortCase = 'CASE id ';
                $ids = [];

                foreach ($chunk->values() as $i => $carton) {
                    $position = $chunkIndex * 500 + $i + 1;
                    $label = 'BOX-'.str_pad((string) $position, 3, '0', STR_PAD_LEFT);

                    $labelCase .= "WHEN {$carton->id} THEN '{$label}' ";
                    $sortCase .= "WHEN {$carton->id} THEN {$position} ";
                    $ids[] = $carton->id;
                }

                DB::update(
                    'UPDATE cartons SET label = '.$labelCase.'END, sort_order = '.$sortCase.'END WHERE id IN ('.implode(',', $ids).')',
                );
            });

            return $cartons->count();
        });
    }
}
