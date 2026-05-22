<?php

namespace App\Domain\Logistics\Actions;

use App\Domain\Logistics\Enums\PackagingType;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Models\Shipment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CreateCartonAction
{
    /**
     * Create a new carton for a shipment with an auto-generated BOX-NNN label.
     *
     * $defaults may override container_number, pallet_number, packaging_type,
     * weights, dimensions, notes, sort_order. The `label` is always generated
     * server-side and any value provided in $defaults is ignored.
     */
    public function execute(Shipment $shipment, array $defaults = []): Carton
    {
        return DB::transaction(function () use ($shipment, $defaults) {
            $label = $this->generateNextLabel($shipment);

            $attributes = array_merge([
                'packaging_type' => PackagingType::CARTON->value,
                'sort_order' => $this->nextSortOrder($shipment),
            ], $defaults, [
                'shipment_id' => $shipment->id,
                'label' => $label, // always server-generated — wins over $defaults
            ]);

            return Carton::create($attributes);
        });
    }

    /**
     * Next BOX-NNN label. In DRAFT shipments labels are always contiguous
     * (DeleteCartonAction renumbers on delete), so max+1 is correct. In other
     * statuses we preserve historical labels (etiquetas físicas, PDFs já
     * enviados ao cliente) and fill the first gap instead.
     */
    private function generateNextLabel(Shipment $shipment): string
    {
        $numbers = $shipment->cartons()
            ->pluck('label')
            ->map(fn ($label) => preg_match('/^BOX-(\d+)$/', (string) $label, $m) ? (int) $m[1] : null)
            ->filter()
            ->sort()
            ->values();

        $next = $shipment->status === ShipmentStatus::DRAFT
            ? (int) ($numbers->max() ?? 0) + 1
            : $this->firstGapOrNext($numbers);

        return 'BOX-'.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    private function firstGapOrNext(Collection $sortedNumbers): int
    {
        $expected = 1;
        foreach ($sortedNumbers as $n) {
            if ($n !== $expected) {
                return $expected;
            }
            $expected++;
        }

        return $expected;
    }

    private function nextSortOrder(Shipment $shipment): int
    {
        return (int) ($shipment->cartons()->max('sort_order') ?? 0) + 1;
    }
}
