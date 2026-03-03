<?php

namespace App\Filament\SupplierPortal\Resources\PurchaseOrderResource\Widgets;

use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;

class SupplierPOShipmentFulfillmentWidget extends Widget
{
    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.shipment-fulfillment';

    protected int|string|array $columnSpan = 'full';

    public ?Model $record = null;

    protected function getViewData(): array
    {
        if (! $this->record instanceof PurchaseOrder) {
            return $this->emptyState();
        }

        $po = $this->record;
        $po->loadMissing(['items.product']);

        $items = $po->items;

        if ($items->isEmpty()) {
            return $this->emptyState();
        }

        $totalQty = 0;
        $totalShipped = 0;
        $pendingCount = 0;

        $mappedItems = $items->map(function ($item) use (&$totalQty, &$totalShipped, &$pendingCount) {
            $shipped = $this->getShippedQuantity($item);
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

            $refs = $this->getShipmentReferences($item);

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

    private function getShippedQuantity($poItem): int
    {
        $baseQuery = $this->buildShipmentItemQuery($poItem);

        return (int) $baseQuery->sum('quantity');
    }

    private function getShipmentReferences($poItem): array
    {
        $baseQuery = $this->buildShipmentItemQuery($poItem)->with('shipment');

        return $baseQuery->get()
            ->pluck('shipment.reference')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function buildShipmentItemQuery($poItem)
    {
        $query = ShipmentItem::query();

        if ($poItem->proforma_invoice_item_id) {
            $query->where(function ($q) use ($poItem) {
                $q->where('purchase_order_item_id', $poItem->id)
                    ->orWhere('proforma_invoice_item_id', $poItem->proforma_invoice_item_id);
            });
        } else {
            $query->where('purchase_order_item_id', $poItem->id);
        }

        $query->whereHas('shipment', function ($q) {
            $q->withoutGlobalScopes()
                ->where('status', '!=', ShipmentStatus::CANCELLED);
        });

        return $query;
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
