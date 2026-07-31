<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\HasDashboardSwitcher;
use App\Filament\Widgets\Financial\CashFlowChart;
use App\Filament\Widgets\Financial\CurrencyExposureChart;
use App\Filament\Widgets\Financial\FinancialKpisWidget;
use App\Filament\Widgets\Financial\PayablesAgingChart;
use App\Filament\Widgets\Financial\ReceivablesAgingChart;
use App\Filament\Widgets\Financial\TopDebtorsWidget;
use App\Filament\Widgets\Financial\UpcomingPayablesTable;
use App\Filament\Widgets\Financial\UpcomingReceivablesTable;
use BackedEnum;
use Filament\Pages\Dashboard;

class FinancialDashboard extends Dashboard
{
    use HasDashboardSwitcher;

    protected static string $routePath = '/dashboard-financeiro';

    protected static ?int $navigationSort = -1;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-banknotes';

    public static function shouldRegisterNavigation(): bool
    {
        // Fora do menu: o acesso é pelo switcher no topo dos dashboards.
        return false;
    }

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
        return [
            FinancialKpisWidget::class,
            CashFlowChart::class,
            ReceivablesAgingChart::class,
            PayablesAgingChart::class,
            UpcomingReceivablesTable::class,
            UpcomingPayablesTable::class,
            TopDebtorsWidget::class,
            CurrencyExposureChart::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 12;
    }
}
