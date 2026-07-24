<?php

namespace App\Filament\Portal\Widgets;

use App\Domain\Financial\Services\ShipmentPaymentSummaryService;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

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

        $remainders = app(ShipmentPaymentSummaryService::class)->openClientRemainderByShipment(
            collect($weeks)->flatMap(fn (array $week) => $week['shipments']->pluck('id')),
        );

        $shipmentPayments = $remainders->map(fn (Collection $byCurrency) => $this->formatByCurrency($byCurrency));

        foreach ($weeks as &$week) {
            $weekTotals = collect();

            foreach ($week['shipments'] as $shipment) {
                foreach ($remainders->get($shipment->id, collect()) as $currency => $remaining) {
                    $weekTotals[$currency] = ($weekTotals[$currency] ?? 0) + $remaining;
                }
            }

            $week['payments_label'] = $weekTotals->isNotEmpty() ? $this->formatByCurrency($weekTotals) : null;
        }

        return [
            'weeks' => $weeks,
            'shipment_payments' => $shipmentPayments,
        ];
    }

    /**
     * @param  Collection<string, int>  $byCurrency
     */
    private function formatByCurrency(Collection $byCurrency): string
    {
        return $byCurrency
            ->map(fn (int $amount, string $currency) => $currency.' '.Money::format($amount))
            ->implode(' · ');
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
