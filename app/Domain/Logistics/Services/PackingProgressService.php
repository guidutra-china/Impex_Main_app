<?php

namespace App\Domain\Logistics\Services;

use App\Domain\Logistics\DTOs\ShipmentItemProgress;
use App\Domain\Logistics\Enums\PackingProgressStatus;
use App\Domain\Logistics\Models\CartonContent;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use Illuminate\Support\Collection;

/**
 * Single source of truth for "how much of each ShipmentItem is packed into cartons?".
 *
 * Two counting modes:
 *
 *   - **Standard item** (no packing_split): packedComplete = SUM(pieces) across
 *     all carton_contents for the item. Straightforward cumulative count.
 *
 *   - **Split item** (packing_split defined on shipment_item): packedComplete =
 *     MIN(sum per part_label), where parts are defined by the split's part_labels.
 *     Each part tracks its own remaining independently. The product is "complete"
 *     when every part's sum reaches the item's total quantity.
 */
class PackingProgressService
{
    /**
     * @return Collection<int, ShipmentItemProgress> keyed by shipment_item_id
     */
    public function forShipment(Shipment $shipment): Collection
    {
        $items = ShipmentItem::query()
            ->where('shipment_id', $shipment->id)
            ->get();

        $contentsByItem = $this->loadContentsForShipment($shipment);

        return $items->mapWithKeys(function (ShipmentItem $item) use ($contentsByItem) {
            $contents = $contentsByItem->get($item->id, collect());
            $progress = $this->buildProgress($item, $contents);

            return [$item->id => $progress];
        });
    }

    public function forShipmentItem(ShipmentItem $item): ShipmentItemProgress
    {
        $contents = CartonContent::query()
            ->whereHas('carton', fn ($q) => $q->where('shipment_id', $item->shipment_id))
            ->where('shipment_item_id', $item->id)
            ->get();

        return $this->buildProgress($item, $contents);
    }

    private function loadContentsForShipment(Shipment $shipment): Collection
    {
        return CartonContent::query()
            ->whereHas('carton', fn ($q) => $q->where('shipment_id', $shipment->id))
            ->whereNotNull('shipment_item_id')
            ->get()
            ->groupBy('shipment_item_id');
    }

    /**
     * @param  iterable<\App\Domain\Logistics\Models\CartonContent>  $contents
     */
    private function buildProgress(ShipmentItem $item, iterable $contents): ShipmentItemProgress
    {
        $contents = collect($contents);
        $total = (int) $item->quantity;

        if (is_array($item->packing_split) && ! empty($item->packing_split['part_labels'] ?? null)) {
            return $this->buildSplitProgress($item, $contents, $total);
        }

        return $this->buildStandardProgress($item, $contents, $total);
    }

    /**
     * Standard (non-split) counting: sum all pieces across standalone and legacy
     * multi-box-set contents. Legacy multi-box-set dedupe (group by set_id) is
     * preserved only for backwards compat with data created before the
     * packing_split model was introduced — when the item has no packing_split,
     * sets are just summed like standalone contents.
     */
    private function buildStandardProgress(ShipmentItem $item, Collection $contents, int $total): ShipmentItemProgress
    {
        $packedComplete = (int) $contents->sum(fn ($c) => (int) $c->pieces);

        $status = $this->deriveStatus($packedComplete, $total);

        return new ShipmentItemProgress(
            shipmentItemId: $item->id,
            total: $total,
            packedComplete: $packedComplete,
            packedInProgress: 0,
            parts: [],
            status: $status,
        );
    }

    /**
     * Split counting: group contents by part_label within the split's set_id,
     * compute sum per part, and report MIN across parts as packedComplete.
     */
    private function buildSplitProgress(ShipmentItem $item, Collection $contents, int $total): ShipmentItemProgress
    {
        $split = $item->packing_split;
        $setId = $split['set_id'] ?? null;
        $partLabels = $split['part_labels'] ?? [];

        $splitContents = $contents
            ->where('multi_box_set_id', $setId)
            ->groupBy('part_label');

        $parts = [];
        $minSum = PHP_INT_MAX;

        foreach ($partLabels as $label) {
            $partContents = $splitContents->get($label, collect());
            $placed = (int) $partContents->sum(fn ($c) => (int) $c->pieces);
            $target = $total;
            $remaining = max(0, $target - $placed);

            $parts[] = [
                'label' => $label,
                'placed' => $placed,
                'target' => $target,
                'remaining' => $remaining,
            ];

            $minSum = min($minSum, $placed);
        }

        $packedComplete = $minSum === PHP_INT_MAX ? 0 : min($minSum, $total);

        $status = $this->deriveStatus($packedComplete, $total);

        return new ShipmentItemProgress(
            shipmentItemId: $item->id,
            total: $total,
            packedComplete: $packedComplete,
            packedInProgress: 0,
            parts: $parts,
            status: $status,
        );
    }

    private function deriveStatus(int $packed, int $total): PackingProgressStatus
    {
        return match (true) {
            $packed <= 0 => PackingProgressStatus::NOT_STARTED,
            $packed >= $total => PackingProgressStatus::COMPLETE,
            default => PackingProgressStatus::PARTIAL,
        };
    }
}
