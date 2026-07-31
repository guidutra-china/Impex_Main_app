<?php

namespace App\Filament\Pages\Concerns;

use App\Filament\Pages\FinancialDashboard;
use App\Filament\Pages\OperationalDashboard;
use Illuminate\Contracts\View\View;

/**
 * Shared header for the two dashboards: page heading on the left, a tab
 * switcher on the right. The switcher renders only for users who can access
 * the financial dashboard — operational access is implied for any
 * authenticated panel user; otherwise the plain heading shows.
 */
trait HasDashboardSwitcher
{
    public function getHeader(): ?View
    {
        $dashboards = [];

        if (FinancialDashboard::canAccess()) {
            $dashboards = [
                [
                    // Nomes específicos ficam no switcher; o menu mostra só "Dashboard".
                    'label' => __('navigation.pages.operational_dashboard'),
                    'url' => OperationalDashboard::getUrl(),
                    'icon' => OperationalDashboard::getNavigationIcon(),
                    'active' => static::class === OperationalDashboard::class,
                ],
                [
                    'label' => __('navigation.pages.financial_dashboard'),
                    'url' => FinancialDashboard::getUrl(),
                    'icon' => FinancialDashboard::getNavigationIcon(),
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
