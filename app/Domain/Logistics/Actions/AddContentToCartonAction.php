<?php

namespace App\Domain\Logistics\Actions;

use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Models\CartonContent;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\Logistics\Services\PackingProgressService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AddContentToCartonAction
{
    public function __construct(
        private readonly PackingProgressService $progress,
        private readonly RecalculateShipmentTotalsAction $recalc,
    ) {}

    /**
     * Add a new content row to a carton.
     *
     * Validation:
     *   - pieces must be > 0
     *   - carton and shipment item must belong to the same shipment
     *   - pieces must not exceed the item's remaining count, UNLESS the caller is
     *     attaching another part of an existing multi-box set (same multiBoxSetId
     *     already present for this item) — in that case remaining is not affected
     *     because the set was already counted.
     *
     * Side effects: persists the content, then dispatches RecalculateShipmentTotalsAction.
     */
    public function execute(
        Carton $carton,
        ShipmentItem $item,
        int $pieces,
        ?string $partLabel = null,
        ?string $multiBoxSetId = null,
    ): CartonContent {
        if ($pieces <= 0) {
            throw new InvalidArgumentException('pieces must be greater than 0');
        }

        if ($carton->shipment_id !== $item->shipment_id) {
            throw new InvalidArgumentException('Carton and ShipmentItem belong to different shipments');
        }

        return DB::transaction(function () use ($carton, $item, $pieces, $partLabel, $multiBoxSetId) {
            $this->validatePieces($item, $pieces, $partLabel, $multiBoxSetId);

            $content = CartonContent::create([
                'carton_id' => $carton->id,
                'shipment_item_id' => $item->id,
                'pieces' => $pieces,
                'part_label' => $partLabel,
                'multi_box_set_id' => $multiBoxSetId,
                'sort_order' => ((int) $carton->contents()->max('sort_order')) + 1,
            ]);

            $this->recalc->execute($carton->shipment);

            return $content;
        });
    }

    /**
     * Validate that $pieces doesn't overshoot the relevant remaining count:
     *   - For split items with matching set_id + part_label: per-part remaining
     *   - Otherwise: whole-item remaining
     */
    private function validatePieces(
        ShipmentItem $item,
        int $pieces,
        ?string $partLabel,
        ?string $multiBoxSetId,
    ): void {
        $progress = $this->progress->forShipmentItem($item);

        if ($progress->isSplit() && $partLabel !== null) {
            $splitSetId = $item->packing_split['set_id'] ?? null;

            if ($splitSetId !== null && $multiBoxSetId === $splitSetId) {
                $partRemaining = $progress->remainingForPart($partLabel);

                if ($pieces > $partRemaining) {
                    throw new InvalidArgumentException(sprintf(
                        'Cannot add %d pieces of "%s" — only %d remaining for that part',
                        $pieces,
                        $partLabel,
                        $partRemaining,
                    ));
                }

                return;
            }
        }

        if ($pieces > $progress->remaining()) {
            throw new InvalidArgumentException(sprintf(
                'Cannot add %d pieces — only %d remaining for shipment item %d',
                $pieces,
                $progress->remaining(),
                $item->id,
            ));
        }
    }
}
