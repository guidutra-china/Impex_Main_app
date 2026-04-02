<?php

namespace App\Filament\Resources\Shipments\Widgets;

use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Logistics\Models\Shipment;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;

class ShipmentSummary extends Widget
{
    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.shipment-summary';

    protected int | string | array $columnSpan = 'full';

    public ?Model $record = null;

    protected function getViewData(): array
    {
        /** @var Shipment $shipment */
        $shipment = $this->record;

        $shipment->loadMissing([
            'items.proformaInvoiceItem',
            'additionalCosts',
        ]);

        $currency = $shipment->currency_code ?? 'USD';

        $totalValue = $shipment->items->sum(function ($item) {
            return ($item->proformaInvoiceItem?->unit_price ?? 0) * $item->quantity;
        });

        $totalQuantity = $shipment->items->sum('quantity');
        $productCount = $shipment->items->count();

        $freightCost = $shipment->additionalCosts
            ->filter(fn ($cost) => $cost->cost_type === AdditionalCostType::FREIGHT)
            ->sum('amount_in_document_currency');

        $totalWeight = (float) $shipment->total_gross_weight ?: $shipment->items->sum(fn ($item) => (float) ($item->total_weight ?? 0));

        $totalVolume = (float) ($shipment->total_volume ?? 0);
        $totalPackages = (int) ($shipment->total_packages ?? 0);

        return [
            'currency' => $currency,
            'total_value' => Money::format($totalValue),
            'total_quantity' => number_format($totalQuantity),
            'product_count' => $productCount,
            'freight_cost' => $freightCost > 0 ? Money::format($freightCost) : null,
            'total_weight' => $totalWeight > 0 ? number_format($totalWeight, 1) : null,
            'total_volume' => $totalVolume > 0 ? number_format($totalVolume, 2) : null,
            'total_packages' => $totalPackages > 0 ? number_format($totalPackages) : null,
        ];
    }
}
