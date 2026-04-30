<?php

namespace App\Filament\Portal\Widgets;

use App\Domain\Logistics\Models\Shipment;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

class RecentEtaChangesWidget extends Widget
{
    protected string $view = 'filament.portal.widgets.recent-eta-changes';

    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $tenant = Filament::getTenant();
        if (! $tenant) {
            return false;
        }

        return Shipment::query()
            ->where('company_id', $tenant->getKey())
            ->whereNotNull('eta_changed_at')
            ->where('eta_changed_at', '>=', now()->subDays(14))
            ->exists();
    }

    protected function getViewData(): array
    {
        $tenant = Filament::getTenant();

        $shipments = Shipment::query()
            ->where('company_id', $tenant?->getKey())
            ->whereNotNull('eta_changed_at')
            ->where('eta_changed_at', '>=', now()->subDays(14))
            ->orderByDesc('eta_changed_at')
            ->limit(10)
            ->get();

        return ['shipments' => $shipments];
    }
}
