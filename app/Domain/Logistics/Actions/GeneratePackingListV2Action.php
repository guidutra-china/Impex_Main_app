<?php

namespace App\Domain\Logistics\Actions;

use App\Domain\Logistics\Enums\PackagingType;
use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Models\CartonContent;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use Illuminate\Support\Facades\DB;

/**
 * V2 of GeneratePackingListAction — writes directly to cartons + carton_contents.
 *
 * Port of the legacy action's logic (separate and mixed modes) using product
 * packaging metadata (pcs_per_carton, carton_weight, carton dimensions) to
 * generate an initial packing list. Operators then fine-tune via the
 * PackingListBuilder Livewire component.
 *
 * Called by the "Generate from Items" button in the new UI.
 */
class GeneratePackingListV2Action
{
    public function __construct(
        private readonly CreateCartonAction $createCarton,
        private readonly RecalculateShipmentTotalsAction $recalc,
    ) {}

    public function execute(Shipment $shipment, bool $mixed = false, array $mixedBoxConfig = []): int
    {
        // Wipe existing cartons before regenerating — mirrors legacy behavior.
        DB::transaction(function () use ($shipment) {
            $shipment->cartons()->each(function (Carton $carton) {
                $carton->contents()->delete();
                $carton->delete();
            });
        });

        $count = $mixed
            ? $this->generateMixed($shipment, $mixedBoxConfig)
            : $this->generateSeparate($shipment);

        $this->recalc->execute($shipment);

        return $count;
    }

    /**
     * Separate mode: each ShipmentItem gets its own sequence of cartons sized by pcs_per_carton.
     */
    protected function generateSeparate(Shipment $shipment): int
    {
        $created = 0;

        $items = $shipment->items()->with('proformaInvoiceItem.product.packaging')->get();

        foreach ($items as $shipmentItem) {
            $totalQty = (int) $shipmentItem->quantity;
            if ($totalQty <= 0) {
                continue;
            }

            $packaging = $shipmentItem->proformaInvoiceItem?->product?->packaging;
            $pcsPerCarton = (int) ($packaging?->pcs_per_carton ?? 0);

            if ($pcsPerCarton <= 0) {
                // No packaging metadata — create a single carton holding everything
                $this->createCartonWithContent(
                    $shipment,
                    $shipmentItem,
                    $totalQty,
                    $this->cartonDefaultsFromPackaging($packaging),
                );
                $created++;

                continue;
            }

            $fullCartons = intdiv($totalQty, $pcsPerCarton);
            $remainder = $totalQty % $pcsPerCarton;

            $defaults = $this->cartonDefaultsFromPackaging($packaging);

            for ($i = 0; $i < $fullCartons; $i++) {
                $this->createCartonWithContent($shipment, $shipmentItem, $pcsPerCarton, $defaults);
                $created++;
            }

            if ($remainder > 0) {
                // Last carton is partial — scale weight proportionally
                $partialDefaults = $this->scaleDefaults($defaults, $remainder / $pcsPerCarton);
                $this->createCartonWithContent($shipment, $shipmentItem, $remainder, $partialDefaults);
                $created++;
            }
        }

        return $created;
    }

    /**
     * Mixed mode: share cartons across items. Max boxes = max(ceil(qty / pcs_per_carton)) across items.
     * Each carton holds one content per item that still has remaining pieces.
     */
    protected function generateMixed(Shipment $shipment, array $config): int
    {
        $items = $shipment->items()->with('proformaInvoiceItem.product.packaging')->get();

        if ($items->isEmpty()) {
            return 0;
        }

        $itemState = [];
        $maxBoxes = 0;

        foreach ($items as $shipmentItem) {
            $totalQty = (int) $shipmentItem->quantity;
            if ($totalQty <= 0) {
                continue;
            }

            $packaging = $shipmentItem->proformaInvoiceItem?->product?->packaging;
            $pcsPerCarton = (int) ($packaging?->pcs_per_carton ?? 0);
            if ($pcsPerCarton <= 0) {
                $pcsPerCarton = $totalQty;
            }

            $boxesNeeded = (int) ceil($totalQty / $pcsPerCarton);
            $maxBoxes = max($maxBoxes, $boxesNeeded);

            $itemState[] = [
                'shipmentItem' => $shipmentItem,
                'pcsPerCarton' => $pcsPerCarton,
                'remaining' => $totalQty,
            ];
        }

        if ($maxBoxes <= 0 || empty($itemState)) {
            return 0;
        }

        $boxDefaults = $this->mixedBoxDefaultsFromConfig($config);

        $created = 0;

        for ($boxIndex = 0; $boxIndex < $maxBoxes; $boxIndex++) {
            $carton = $this->createCarton->execute($shipment, $boxDefaults);
            $contentOrder = 0;

            foreach ($itemState as &$state) {
                if ($state['remaining'] <= 0) {
                    continue;
                }

                $take = min($state['pcsPerCarton'], $state['remaining']);
                $state['remaining'] -= $take;
                $contentOrder++;

                CartonContent::create([
                    'carton_id' => $carton->id,
                    'shipment_item_id' => $state['shipmentItem']->id,
                    'pieces' => $take,
                    'sort_order' => $contentOrder,
                ]);
            }
            unset($state);

            $created++;
        }

        return $created;
    }

    private function createCartonWithContent(Shipment $shipment, ShipmentItem $item, int $pieces, array $defaults): Carton
    {
        $carton = $this->createCarton->execute($shipment, $defaults);

        CartonContent::create([
            'carton_id' => $carton->id,
            'shipment_item_id' => $item->id,
            'pieces' => $pieces,
            'sort_order' => 1,
        ]);

        return $carton;
    }

    private function cartonDefaultsFromPackaging($packaging): array
    {
        if (! $packaging) {
            return ['packaging_type' => PackagingType::CARTON->value];
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
        $typeValue = $type instanceof PackagingType ? $type->value : (string) $type;

        return array_filter([
            'packaging_type' => $typeValue,
            'gross_weight' => $gross > 0 ? $gross : null,
            'net_weight' => $net > 0 ? $net : null,
            'length' => $length > 0 ? $length : null,
            'width' => $width > 0 ? $width : null,
            'height' => $height > 0 ? $height : null,
            'volume' => $cbm > 0 ? $cbm : null,
        ], fn ($v) => $v !== null);
    }

    private function scaleDefaults(array $defaults, float $ratio): array
    {
        $scaled = $defaults;
        foreach (['gross_weight', 'net_weight'] as $key) {
            if (isset($scaled[$key])) {
                $scaled[$key] = round($scaled[$key] * $ratio, 3);
            }
        }

        return $scaled;
    }

    private function mixedBoxDefaultsFromConfig(array $config): array
    {
        $gross = ! empty($config['gross_weight']) ? (float) $config['gross_weight'] : null;
        $net = ! empty($config['net_weight']) ? (float) $config['net_weight'] : null;
        $length = ! empty($config['length']) ? (float) $config['length'] : null;
        $width = ! empty($config['width']) ? (float) $config['width'] : null;
        $height = ! empty($config['height']) ? (float) $config['height'] : null;
        $volume = ! empty($config['volume']) ? (float) $config['volume'] : null;

        if ($volume === null && $length && $width && $height) {
            $volume = round(($length * $width * $height) / 1_000_000, 4);
        }
        if ($net === null && $gross !== null) {
            $net = round($gross * 0.9, 3);
        }

        $rawType = $config['packaging_type'] ?? null;
        $type = $rawType instanceof PackagingType
            ? $rawType
            : (PackagingType::tryFrom((string) ($rawType ?? '')) ?? PackagingType::CARTON);

        return array_filter([
            'packaging_type' => $type->value,
            'gross_weight' => $gross,
            'net_weight' => $net,
            'length' => $length,
            'width' => $width,
            'height' => $height,
            'volume' => $volume,
        ], fn ($v) => $v !== null);
    }
}
