<?php

namespace App\Filament\Pages\Concerns;

use App\Filament\Pages\FinancialDashboard;
use App\Filament\Pages\OperationalDashboard;
use Illuminate\Contracts\View\View;

/**
 * Shared header for the two dashboards: page heading on the left, a tab
 * switcher on the right. The switcher only renders when the user can access
 * BOTH dashboards; otherwise the plain heading shows.
 */
trait HasDashboardSwitcher
{
    public function getHeader(): ?View
    {
        $dashboards = [];

        if (OperationalDashboard::canAccess() && FinancialDashboard::canAccess()) {
            $dashboards = [
                [
                    'label' => __('navigation.pages.operational_dashboard'),
                    'url' => OperationalDashboard::getUrl(),
                    'icon' => 'heroicon-o-truck',
                    'active' => static::class === OperationalDashboard::class,
                ],
                [
                    'label' => __('navigation.pages.financial_dashboard'),
                    'url' => FinancialDashboard::getUrl(),
                    'icon' => 'heroicon-o-banknotes',
                    'active' => static::class === FinancialDashboard::class,
                ],
            ];
        }

        return view('filament.components.dashboard-switcher', [
            'heading' => $this->getTitle(),
            'dashboards' => $dashboards,
        ]);
    }
}
