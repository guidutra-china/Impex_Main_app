<?php

namespace App\Domain\Logistics\Actions;

use App\Domain\Logistics\Enums\PackagingType;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentContainer;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\Logistics\Models\ShipmentPallet;
use App\Domain\Logistics\Services\PackingProgressService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Bulk-create cartons + contents for a shipment item, placing them all inside
 * a target container/pallet in one operation.
 *
 * Uses DB::table()->insert() (not Eloquent Model::create() in a loop) so it
 * scales to thousands of cartons — a typical shipping workflow has 5 containers
 * × 4000 cartons = 20,000 rows, and per-row creation would take tens of seconds.
 *
 * Reads ProductPackaging.pcs_per_carton + dimensions + weight to auto-fill
 * each carton's box-level fields.
 */
class BulkFillAction
{
    public function __construct(
        private readonly PackingProgressService $progress,
        private readonly RecalculateShipmentTotalsAction $recalc,
    ) {}

    /**
     * Pack $pieces of $item into $container (or $pallet) as auto-sized cartons.
     *
     * @return int number of cartons created
     */
    public function execute(
        ShipmentItem $item,
        int $pieces,
        ?ShipmentContainer $container = null,
        ?ShipmentPallet $pallet = null,
    ): int {
        if ($pieces <= 0) {
            throw new InvalidArgumentException('pieces must be greater than 0');
        }

        if ($item->packing_split !== null) {
            throw new InvalidArgumentException(
                'Split items cannot be bulk-filled — use the per-part placement controls'
            );
        }

        $progress = $this->progress->forShipmentItem($item);
        if ($pieces > $progress->remaining()) {
            throw new InvalidArgumentException(sprintf(
                'Cannot pack %d pieces — only %d remaining for %s',
                $pieces,
                $progress->remaining(),
                $item->product_name ?? ('item #'.$item->id),
            ));
        }

        // Resolve the carton parent: pallet wins, and pallet's container is inherited
        $containerId = null;
        $palletId = null;
        if ($pallet) {
            $palletId = $pallet->id;
            $containerId = $pallet->shipment_container_id ?? $container?->id;
        } elseif ($container) {
            $containerId = $container->id;
        }

        $shipment = $item->shipment;

        // Load product with trashed — soft-deleted products still have valid
        // packaging data, and shipment items frequently reference them.
        $piItem = $item->proformaInvoiceItem;
        $product = $piItem?->product_id
            ? \App\Domain\Catalog\Models\Product::withTrashed()->with('packaging')->find($piItem->product_id)
            : null;
        $packaging = $product?->packaging;
        $pcsPerCarton = (int) ($packaging?->pcs_per_carton ?? 0);

        if ($pcsPerCarton <= 0) {
            // No packaging metadata — pack everything into a single carton
            $pcsPerCarton = $pieces;
        }

        $fullCartons = intdiv($pieces, $pcsPerCarton);
        $remainder = $pieces % $pcsPerCarton;

        // Only create full cartons — the remainder (if any) is left unpacked
        // so the operator can create a mixed box manually with different dims/weight.
        $totalCartons = $fullCartons;
        $packedPieces = $fullCartons * $pcsPerCarton;

        $defaults = $this->cartonDefaultsFromPackaging($packaging);

        $created = DB::transaction(function () use (
            $shipment,
            $item,
            $containerId,
            $palletId,
            $pcsPerCarton,
            $totalCartons,
            $defaults,
        ) {
            if ($totalCartons <= 0) {
                return 0;
            }

            $now = Carbon::now();
            $startNum = $this->getNextLabelNumber($shipment);
            $startSort = (int) ($shipment->cartons()->max('sort_order') ?? 0) + 1;

            // Build carton rows — only full cartons
            $cartonRows = [];
            for ($i = 0; $i < $totalCartons; $i++) {
                $row = array_merge(
                    $defaults,
                    [
                        'shipment_id' => $shipment->id,
                        'shipment_container_id' => $containerId,
                        'shipment_pallet_id' => $palletId,
                        'label' => 'BOX-'.str_pad((string) ($startNum + $i), 3, '0', STR_PAD_LEFT),
                        'sort_order' => $startSort + $i,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );
                $cartonRows[] = $row;
            }

            // Track the last ID before insert so we can fetch the new rows back
            $maxIdBefore = (int) (DB::table('cartons')->where('shipment_id', $shipment->id)->max('id') ?? 0);

            // Bulk insert cartons in chunks to stay safely under max_allowed_packet
            foreach (array_chunk($cartonRows, 500) as $chunk) {
                DB::table('cartons')->insert($chunk);
            }

            // Fetch newly inserted cartons in the same order we created them
            $insertedCartons = DB::table('cartons')
                ->where('shipment_id', $shipment->id)
                ->where('id', '>', $maxIdBefore)
                ->orderBy('id')
                ->get(['id', 'label']);

            if ($insertedCartons->count() !== $totalCartons) {
                throw new \RuntimeException(sprintf(
                    'Expected %d cartons after bulk insert, found %d',
                    $totalCartons,
                    $insertedCartons->count(),
                ));
            }

            // Build content rows — 1 content per carton, all full
            $contentRows = [];
            foreach ($insertedCartons as $carton) {
                $contentRows[] = [
                    'carton_id' => $carton->id,
                    'shipment_item_id' => $item->id,
                    'pieces' => $pcsPerCarton,
                    'part_label' => null,
                    'multi_box_set_id' => null,
                    'sort_order' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            // Bulk insert contents
            foreach (array_chunk($contentRows, 500) as $chunk) {
                DB::table('carton_contents')->insert($chunk);
            }

            return $totalCartons;
        });

        // Recalc shipment totals outside transaction
        $this->recalc->execute($shipment);

        return $created;
    }

    /**
     * Bulk fill always appends contiguous labels at max+1, even in non-DRAFT
     * shipments where CreateCartonAction would fill gaps. Splitting a single
     * bulk op across gaps (BOX-003, BOX-006, BOX-008…) would produce a
     * confusing, non-contiguous range — operators expect a clean tail.
     */
    private function getNextLabelNumber(Shipment $shipment): int
    {
        $labels = $shipment->cartons()->pluck('label');

        $max = 0;
        foreach ($labels as $label) {
            if (preg_match('/^BOX-(\d+)$/', (string) $label, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return $max + 1;
    }

    private function cartonDefaultsFromPackaging($packaging): array
    {
        $defaults = [
            'container_number' => null,
            'pallet_number' => null,
            'packaging_type' => PackagingType::CARTON->value,
            'gross_weight' => null,
            'net_weight' => null,
            'length' => null,
            'width' => null,
            'height' => null,
            'volume' => null,
            'notes' => null,
        ];

        if (! $packaging) {
            return $defaults;
        }

        $length = (float) ($packaging->carton_length ?? 0);
        $width = (float) ($packaging->carton_width ?? 0);
        $height = (float) ($packaging->carton_height ?? 0);
        $cbm = (float) ($packaging->carton_cbm ?? 0);

        if ($cbm <= 0 && $length > 0 && $width > 0 && $height > 0) {
            $cbm = round(($length * $width * $height) / 1_000_000, 4);
        }

        $gross = (float) ($packaging->carton_weight ?? 0);
        $net = (float) ($packaging->carton_net_weight ?? 0);
        if ($net <= 0 && $gross > 0) {
            $net = round($gross * 0.9, 3);
        }

        $type = $packaging->packaging_type ?? PackagingType::CARTON;
        $defaults['packaging_type'] = $type instanceof PackagingType ? $type->value : (string) $type;

        if ($gross > 0) {
            $defaults['gross_weight'] = $gross;
        }
        if ($net > 0) {
            $defaults['net_weight'] = $net;
        }
        if ($length > 0) {
            $defaults['length'] = $length;
        }
        if ($width > 0) {
            $defaults['width'] = $width;
        }
        if ($height > 0) {
            $defaults['height'] = $height;
        }
        if ($cbm > 0) {
            $defaults['volume'] = $cbm;
        }

        return $defaults;
    }

    private function scaleDefaults(array $defaults, float $ratio): array
    {
        $scaled = $defaults;
        foreach (['gross_weight', 'net_weight'] as $key) {
            if ($scaled[$key] !== null) {
                $scaled[$key] = round($scaled[$key] * $ratio, 3);
            }
        }

        return $scaled;
    }
}
