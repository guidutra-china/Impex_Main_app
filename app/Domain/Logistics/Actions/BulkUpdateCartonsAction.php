<?php

namespace App\Domain\Logistics\Actions;

use App\Domain\Logistics\Models\CartonContent;
use App\Domain\Logistics\Models\Shipment;
use Illuminate\Support\Facades\DB;

class BulkUpdateCartonsAction
{
    public function __construct(
        private readonly RecalculateShipmentTotalsAction $recalc,
    ) {}

    /**
     * Apply the same attributes to many cartons of a shipment at once.
     *
     * Behaves like UpdateCartonAction applied to each carton, but performs a
     * single bulk UPDATE and recalculates shipment totals once at the end —
     * updating a 2,700-box subgroup must not issue 2,700 update+recalc cycles.
     *
     * The weight-share consistency rule is validated upfront per carton;
     * cartons that would fail are skipped while the rest are updated.
     *
     * @param  array<int>  $cartonIds
     * @return array{updated: int, skipped: int, error: string|null}
     */
    public function execute(Shipment $shipment, array $cartonIds, array $attributes): array
    {
        unset($attributes['id'], $attributes['shipment_id'], $attributes['label']);

        $cartons = $shipment->cartons()->whereIn('id', $cartonIds)->get(['id', 'gross_weight']);

        if ($cartons->isEmpty() || $attributes === []) {
            return ['updated' => 0, 'skipped' => 0, 'error' => null];
        }

        $shareStats = CartonContent::query()
            ->whereIn('carton_id', $cartons->pluck('id'))
            ->selectRaw('carton_id, COUNT(*) as total_rows, COUNT(weight_share) as rows_with_share, COALESCE(SUM(weight_share), 0) as share_sum')
            ->groupBy('carton_id')
            ->get()
            ->keyBy('carton_id');

        $validIds = [];
        $skipped = 0;
        $lastError = null;

        foreach ($cartons as $carton) {
            $error = $this->weightShareError($carton->gross_weight, $attributes, $shareStats->get($carton->id));

            if ($error !== null) {
                $skipped++;
                $lastError = $error;

                continue;
            }

            $validIds[] = $carton->id;
        }

        if ($validIds !== []) {
            DB::transaction(function () use ($shipment, $validIds, $attributes) {
                $shipment->cartons()->whereIn('id', $validIds)->update($attributes);

                $this->recalc->execute($shipment);
            });
        }

        return ['updated' => count($validIds), 'skipped' => $skipped, 'error' => $lastError];
    }

    /**
     * Mirrors UpdateCartonAction::validateWeightShareConsistency() against the
     * carton's post-update gross weight, using aggregated content stats.
     */
    private function weightShareError(mixed $currentGross, array $attributes, ?object $stats): ?string
    {
        if ($stats === null || (int) $stats->rows_with_share === 0) {
            return null;
        }

        if ((int) $stats->rows_with_share !== (int) $stats->total_rows) {
            return 'Weight share must be set on all carton contents or none (all-or-nothing).';
        }

        $newGross = array_key_exists('gross_weight', $attributes) ? $attributes['gross_weight'] : $currentGross;

        if ($newGross === null) {
            return 'Cannot validate weight share: carton.gross_weight is null.';
        }

        if (abs((float) $stats->share_sum - (float) $newGross) > 0.001) {
            return sprintf(
                'Sum of weight_share (%.3f) does not equal carton.gross_weight (%.3f).',
                (float) $stats->share_sum,
                (float) $newGross,
            );
        }

        return null;
    }
}
