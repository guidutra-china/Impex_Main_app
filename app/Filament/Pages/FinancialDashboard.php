<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\HasDashboardSwitcher;
use BackedEnum;
use Filament\Pages\Dashboard;

class FinancialDashboard extends Dashboard
{
    use HasDashboardSwitcher;

    protected static string $routePath = '/dashboard-financeiro';

    protected static ?int $navigationSort = -1;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-banknotes';

    public static function getNavigationLabel(): string
    {
        return __('navigation.pages.financial_dashboard');
    }

    public function getTitle(): string
    {
        return __('navigation.pages.financial_dashboard');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-financial-dashboard') ?? false;
    }

    public function mount(): void
    {
        // Chegou direto no financeiro: preferência atendida, não redirecionar depois.
        session()->put('dashboard_preference_applied', true);
    }

    public function getWidgets(): array
    {
        return [];
    }
}
