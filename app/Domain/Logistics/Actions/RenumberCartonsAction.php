<?php

namespace App\Domain\Logistics\Actions;

use App\Domain\Logistics\Models\Shipment;
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

            foreach ($cartons as $carton) {
                DB::table('cartons')->where('id', $carton->id)->update([
                    'label' => '__TMP-'.$carton->id,
                ]);
            }

            foreach ($cartons->values() as $i => $carton) {
                DB::table('cartons')->where('id', $carton->id)->update([
                    'label' => 'BOX-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                    'sort_order' => $i + 1,
                ]);
            }

            return $cartons->count();
        });
    }
}
