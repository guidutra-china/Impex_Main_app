<?php

namespace App\Filament\Portal\Widgets;

use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

class UpcomingArrivalsWidget extends Widget
{
    protected string $view = 'portal.widgets.upcoming-arrivals';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $tenant = Filament::getTenant();
        $today = Carbon::today();

        $baseQuery = Shipment::where('company_id', $tenant->id)
            ->whereNotIn('status', [
                ShipmentStatus::ARRIVED,
                ShipmentStatus::CANCELLED,
            ])
            ->whereNotNull('eta');

        $weeks = [];

        for ($i = 0; $i < 4; $i++) {
            $weekStart = $today->copy()->addWeeks($i)->startOfWeek();
            $weekEnd = $today->copy()->addWeeks($i)->endOfWeek();

            if ($i === 0) {
                $weekStart = $today->copy();
            }

            $shipments = (clone $baseQuery)
                ->whereBetween('eta', [$weekStart, $weekEnd])
                ->orderBy('eta')
                ->get();

            $weeks[] = [
                'label' => $this->getWeekLabel($i, $weekStart, $weekEnd),
                'range' => $weekStart->format('d/m').' - '.$weekEnd->format('d/m'),
                'shipments' => $shipments,
                'count' => $shipments->count(),
                'index' => $i,
            ];
        }

        return [
            'weeks' => $weeks,
        ];
    }

    protected function getWeekLabel(int $index, Carbon $start, Carbon $end): string
    {
        return match ($index) {
            0 => __('widgets.arrivals.this_week'),
            1 => __('widgets.arrivals.week_2'),
            2 => __('widgets.arrivals.week_3'),
            3 => __('widgets.arrivals.week_4'),
            default => __('widgets.arrivals.week').' '.($index + 1),
        };
    }
}
