<?php

namespace App\Filament\Portal\Resources\ProformaInvoiceResource\Widgets;

use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;

class PortalShipmentFulfillmentWidget extends Widget
{
    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.shipment-fulfillment';

    protected int|string|array $columnSpan = 'full';

    public ?Model $record = null;

    protected function getViewData(): array
    {
        if (! $this->record instanceof ProformaInvoice) {
            return $this->emptyState();
        }

        $pi = $this->record;
        $pi->loadMissing(['items.product', 'items.shipmentItems.shipment']);

        $items = $pi->items;

        if ($items->isEmpty()) {
            return $this->emptyState();
        }

        $totalQty = 0;
        $totalShipped = 0;
        $pendingCount = 0;

        $mappedItems = $items->map(function ($item) use (&$totalQty, &$totalShipped, &$pendingCount) {
            $activeShipmentItems = $item->shipmentItems
                ->filter(fn ($si) => $si->shipment && $si->shipment->status !== ShipmentStatus::CANCELLED);

            $shipped = $activeShipmentItems->sum('quantity');
            $remaining = max(0, $item->quantity - $shipped);

            $totalQty += $item->quantity;
            $totalShipped += $shipped;

            if ($remaining > 0) {
                $pendingCount++;
            }

            $status = match (true) {
                $remaining <= 0 => 'fully_shipped',
                $shipped > 0 => 'partial',
                default => 'pending',
            };

            $refs = $activeShipmentItems
                ->pluck('shipment.reference')
                ->unique()
                ->values()
                ->all();

            return [
                'product_name' => $item->product_name,
                'quantity' => $item->quantity,
                'unit' => $item->unit ?? 'pcs',
                'shipped' => $shipped,
                'remaining' => $remaining,
                'status' => $status,
                'shipment_refs' => $refs,
            ];
        })->all();

        $totalRemaining = max(0, $totalQty - $totalShipped);
        $progress = $totalQty > 0 ? round(($totalShipped / $totalQty) * 100, 1) : 0;
        $isFullyShipped = $totalRemaining <= 0;

        $fullyShippedCount = collect($mappedItems)->where('status', 'fully_shipped')->count();
        $partialCount = collect($mappedItems)->where('status', 'partial')->count();

        $cards = [
            [
                'label' => 'Total Items',
                'value' => count($mappedItems),
                'description' => $totalQty . ' units total',
                'icon' => 'heroicon-o-cube',
                'color' => 'primary',
            ],
            [
                'label' => 'Fully Shipped',
                'value' => $fullyShippedCount . ' / ' . count($mappedItems),
                'description' => number_format($totalShipped) . ' units shipped',
                'icon' => 'heroicon-o-check-circle',
                'color' => $isFullyShipped ? 'success' : ($fullyShippedCount > 0 ? 'info' : 'gray'),
            ],
            [
                'label' => 'Remaining',
                'value' => number_format($totalRemaining) . ' units',
                'description' => $pendingCount . ' item(s) pending',
                'icon' => 'heroicon-o-clock',
                'color' => $totalRemaining > 0 ? 'warning' : 'success',
            ],
        ];

        if ($partialCount > 0) {
            $cards[] = [
                'label' => 'Partial Shipments',
                'value' => $partialCount,
                'description' => 'Items partially shipped',
                'icon' => 'heroicon-o-arrow-path',
                'color' => 'warning',
            ];
        }

        return [
            'cards' => $cards,
            'progress' => $progress,
            'items' => $mappedItems,
            'totals' => [
                'quantity' => $totalQty,
                'shipped' => $totalShipped,
                'remaining' => $totalRemaining,
            ],
            'isFullyShipped' => $isFullyShipped,
            'showFinalizationStatus' => false,
            'pendingItemsCount' => $pendingCount,
        ];
    }

    private function emptyState(): array
    {
        return [
            'cards' => [],
            'progress' => null,
            'items' => [],
            'totals' => ['quantity' => 0, 'shipped' => 0, 'remaining' => 0],
            'isFullyShipped' => false,
            'showFinalizationStatus' => false,
            'pendingItemsCount' => 0,
        ];
    }
}
