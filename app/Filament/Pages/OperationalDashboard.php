<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\HasDashboardSwitcher;
use App\Filament\Widgets\MyProjectsWidget;
use App\Filament\Widgets\OperationalAlertsWidget;
use App\Filament\Widgets\PipelineCountsWidget;
use App\Filament\Widgets\SupplierAuditStatsWidget;
use BackedEnum;
use Filament\Pages\Dashboard;

class OperationalDashboard extends Dashboard
{
    use HasDashboardSwitcher;

    protected static string $routePath = '/';

    protected static ?int $navigationSort = -2;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-truck';

    public static function getNavigationLabel(): string
    {
        return __('navigation.pages.operational_dashboard');
    }

    public function getTitle(): string
    {
        return __('navigation.pages.operational_dashboard');
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function mount(): void
    {
        // Redireciona uma vez por sessão para o dashboard preferido do usuário.
        if (
            auth()->user()?->default_dashboard === 'financial'
            && FinancialDashboard::canAccess()
            && session()->missing('dashboard_preference_applied')
        ) {
            $this->redirect(FinancialDashboard::getUrl());
        }

        session()->put('dashboard_preference_applied', true);
    }

    public function getWidgets(): array
    {
        return [
            OperationalAlertsWidget::class,
            PipelineCountsWidget::class,
            MyProjectsWidget::class,
            SupplierAuditStatsWidget::class,
        ];
    }
}
