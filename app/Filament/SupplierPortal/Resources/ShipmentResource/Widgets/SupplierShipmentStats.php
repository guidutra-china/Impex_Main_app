<?php

namespace App\Filament\SupplierPortal\Resources\ShipmentResource\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SupplierShipmentStats extends StatsOverviewWidget
{
    public $record;

    protected function getStats(): array
    {
        $shipment = $this->record;

        return [
            Stat::make('Status', $shipment->status->getLabel())
                ->color($shipment->status->getColor()),
            Stat::make('Transport', $shipment->transport_mode?->getLabel() ?? '—'),
            Stat::make('Items', $shipment->total_items_count),
        ];
    }
}
