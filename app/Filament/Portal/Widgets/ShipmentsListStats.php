<?php

namespace App\Filament\Portal\Widgets;

use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

class ShipmentsListStats extends Widget
{
    protected string $view = 'portal.widgets.shipments-list-stats';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $tenant = Filament::getTenant();
        $query = Shipment::where('company_id', $tenant->id);

        $total = $query->count();
        $active = (clone $query)->whereNotIn('status', [
            ShipmentStatus::ARRIVED,
            ShipmentStatus::CANCELLED,
        ])->count();
        $inTransit = (clone $query)->where('status', ShipmentStatus::IN_TRANSIT)->count();
        $arrived = (clone $query)->where('status', ShipmentStatus::ARRIVED)->count();

        $statusBreakdown = [];
        foreach (ShipmentStatus::cases() as $status) {
            $count = (clone $query)->where('status', $status)->count();
            if ($count > 0) {
                $statusBreakdown[] = [
                    'label' => $status->getEnglishLabel(),
                    'count' => $count,
                    'color' => $status->getColor(),
                    'icon' => $status->getIcon(),
                    'percentage' => $total > 0 ? round(($count / $total) * 100, 1) : 0,
                ];
            }
        }

        return [
            'total' => $total,
            'active' => $active,
            'inTransit' => $inTransit,
            'arrived' => $arrived,
            'statusBreakdown' => $statusBreakdown,
        ];
    }
}
