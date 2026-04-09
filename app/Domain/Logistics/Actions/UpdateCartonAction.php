<?php

namespace App\Domain\Logistics\Actions;

use App\Domain\Logistics\Models\Carton;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class UpdateCartonAction
{
    public function __construct(
        private readonly RecalculateShipmentTotalsAction $recalc,
    ) {}

    /**
     * Update mutable carton fields. `id` and `shipment_id` in $attributes are ignored.
     *
     * If the resulting carton has contents with weight_share set, validates
     * SUM(weight_share) == gross_weight within a 0.001 kg tolerance, all-or-nothing.
     */
    public function execute(Carton $carton, array $attributes): Carton
    {
        unset($attributes['id'], $attributes['shipment_id']);

        return DB::transaction(function () use ($carton, $attributes) {
            $carton->fill($attributes)->save();

            $this->validateWeightShareConsistency($carton->fresh('contents'));

            $this->recalc->execute($carton->shipment);

            return $carton->fresh('contents');
        });
    }

    private function validateWeightShareConsistency(Carton $carton): void
    {
        $contents = $carton->contents;

        if ($contents->isEmpty()) {
            return;
        }

        $withShare = $contents->whereNotNull('weight_share');

        if ($withShare->isEmpty()) {
            return;
        }

        if ($withShare->count() !== $contents->count()) {
            throw new InvalidArgumentException(
                'Weight share must be set on all carton contents or none (all-or-nothing).'
            );
        }

        if ($carton->gross_weight === null) {
            throw new InvalidArgumentException(
                'Cannot validate weight share: carton.gross_weight is null.'
            );
        }

        $sum = (float) $withShare->sum(fn ($c) => (float) $c->weight_share);
        $gross = (float) $carton->gross_weight;

        if (abs($sum - $gross) > 0.001) {
            throw new InvalidArgumentException(sprintf(
                'Sum of weight_share (%.3f) does not equal carton.gross_weight (%.3f).',
                $sum,
                $gross,
            ));
        }
    }
}
