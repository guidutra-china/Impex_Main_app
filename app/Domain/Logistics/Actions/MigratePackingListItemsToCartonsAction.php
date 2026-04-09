<?php

namespace App\Domain\Logistics\Actions;

use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Models\CartonContent;
use App\Domain\Logistics\Models\PackingListItem;
use App\Domain\Logistics\Models\Shipment;
use Illuminate\Support\Str;

class MigratePackingListItemsToCartonsAction
{
    /**
     * Convert legacy packing_list_items rows for a shipment into cartons + carton_contents.
     *
     * Idempotent: existing cartons (matched by shipment_id + label) and existing
     * contents (matched by carton_id + shipment_item_id + part_label) are reused.
     *
     * Multi-box handling: when a shipment_item has any sibling with
     * is_primary_package = false, the action allocates N ULIDs (one per physical
     * unit, where N = quantity of any sibling — they should all match) and
     * distributes them positionally across the cartons of each sibling, so the
     * Nth carton of "Frame" pairs with the Nth carton of "Accessories" via the
     * same multi_box_set_id.
     */
    public function execute(Shipment $shipment): void
    {
        $items = $shipment->packingListItems()->orderBy('carton_from')->get();

        if ($items->isEmpty()) {
            return;
        }

        $unitSetsByItem = $this->buildUnitSetsMap($items);

        foreach ($items as $item) {
            $cartonIndex = 0;
            for ($n = $item->carton_from; $n <= $item->carton_to; $n++) {
                $carton = $this->firstOrCreateCarton($shipment, $item, $n);

                $setIds = $unitSetsByItem[$item->shipment_item_id] ?? [];
                $setId = $setIds[$cartonIndex] ?? null;

                $this->firstOrCreateContent($carton, $item, $setId);
                $cartonIndex++;
            }
        }
    }

    /**
     * Build a map of shipment_item_id => array<int, ULID string>.
     *
     * Each shipment_item_id with at least one non-primary sibling receives an
     * array of ULIDs whose length equals the legacy `quantity` of any sibling
     * (the number of physical units packed across the multi-box set).
     *
     * Items without multi-box siblings are absent from the map.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, PackingListItem>  $items
     * @return array<int, array<int, string>>
     */
    private function buildUnitSetsMap($items): array
    {
        $byItem = $items->groupBy('shipment_item_id');
        $map = [];

        foreach ($byItem as $shipmentItemId => $group) {
            if ($shipmentItemId === null) {
                continue;
            }

            $hasNonPrimary = $group->contains(fn ($i) => $i->is_primary_package === false);
            if (! $hasNonPrimary) {
                continue;
            }

            // All siblings should pack the same number of physical units.
            // Use the first sibling's quantity as the source of truth.
            $unitsCount = (int) $group->first()->quantity;
            if ($unitsCount <= 0) {
                continue;
            }

            $setIds = [];
            for ($i = 0; $i < $unitsCount; $i++) {
                $setIds[] = (string) Str::ulid();
            }

            $map[(int) $shipmentItemId] = $setIds;
        }

        return $map;
    }

    private function firstOrCreateCarton(Shipment $shipment, PackingListItem $item, int $cartonNumber): Carton
    {
        $label = 'BOX-'.str_pad((string) $cartonNumber, 3, '0', STR_PAD_LEFT);

        return Carton::firstOrCreate(
            [
                'shipment_id' => $shipment->id,
                'label' => $label,
            ],
            [
                'container_number' => $item->container_number,
                'pallet_number' => $item->pallet_number,
                'packaging_type' => $item->packaging_type?->value ?? 'CARTON',
                'gross_weight' => $item->gross_weight,
                'net_weight' => $item->net_weight ?? ($item->gross_weight ? round((float) $item->gross_weight * 0.9, 3) : null),
                'length' => $item->length,
                'width' => $item->width,
                'height' => $item->height,
                'volume' => $item->volume,
                'sort_order' => $cartonNumber,
            ]
        );
    }

    private function firstOrCreateContent(Carton $carton, PackingListItem $item, ?string $setId): void
    {
        CartonContent::firstOrCreate(
            [
                'carton_id' => $carton->id,
                'shipment_item_id' => $item->shipment_item_id,
                'part_label' => $item->package_label,
            ],
            [
                'pieces' => (int) ($item->qty_per_carton ?? 0),
                'multi_box_set_id' => $setId,
            ]
        );
    }
}
