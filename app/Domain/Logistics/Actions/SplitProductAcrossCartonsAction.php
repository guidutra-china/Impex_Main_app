<?php

namespace App\Domain\Logistics\Actions;

use App\Domain\Logistics\Models\ShipmentItem;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SplitProductAcrossCartonsAction
{
    /**
     * Define a split for a shipment item. Persists to $item->packing_split.
     *
     * A split decomposes the product into N part labels (e.g., Frame + Accessories).
     * Each part has its own target equal to the shipment item's total quantity —
     * operators can then distribute the pieces of each part across cartons freely
     * via AddContentToCartonAction.
     *
     * The item is considered "fully packed" when every part's placed sum reaches
     * the item's target quantity. Progress is computed by PackingProgressService
     * as MIN(placed per part), capped at item.quantity.
     *
     * Throws if the item already has a split defined — call clearSplit() first.
     *
     * @param  array<int, string>  $partLabels  ordered labels, minimum 2
     * @return array{set_id: string, part_labels: array<int, string>}
     */
    public function execute(ShipmentItem $item, array $partLabels): array
    {
        if ($item->packing_split !== null) {
            throw new InvalidArgumentException(
                'Shipment item already has a split defined. Clear the existing split before creating a new one.'
            );
        }

        // Normalize labels: trim, drop empties, dedupe
        $labels = array_values(array_unique(array_filter(
            array_map('trim', $partLabels),
            fn ($label) => $label !== '',
        )));

        if (count($labels) < 2) {
            throw new InvalidArgumentException('A split requires at least 2 distinct, non-empty part labels');
        }

        $definition = [
            'set_id' => (string) Str::ulid(),
            'part_labels' => $labels,
        ];

        $item->update(['packing_split' => $definition]);

        return $definition;
    }

    /**
     * Clear the split definition for an item and remove any related carton contents.
     *
     * Use case: operator made a mistake with the split and wants to start over.
     * Returns the number of carton_contents rows removed.
     */
    public function clear(ShipmentItem $item): int
    {
        if ($item->packing_split === null) {
            return 0;
        }

        $setId = $item->packing_split['set_id'] ?? null;

        $removed = 0;
        if ($setId) {
            // Remove split contents for this item
            $shipment = $item->shipment;
            $cartonIds = $shipment->cartons()->pluck('id');

            $removed = \App\Domain\Logistics\Models\CartonContent::query()
                ->whereIn('carton_id', $cartonIds)
                ->where('shipment_item_id', $item->id)
                ->where('multi_box_set_id', $setId)
                ->delete();
        }

        $item->update(['packing_split' => null]);

        return $removed;
    }
}
