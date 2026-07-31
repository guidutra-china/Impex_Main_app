<?php

namespace App\Filament\Widgets\Financial\Concerns;

/**
 * Shared visibility gate for every financial-dashboard widget: only users
 * with the view-financial-dashboard permission see them.
 */
trait HasFinancialDashboardGate
{
    public static function canView(): bool
    {
        return auth()->user()?->can('view-financial-dashboard') ?? false;
    }
}
