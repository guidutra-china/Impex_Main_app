<?php

namespace App\Filament\Portal\Pages;

use App\Filament\Portal\Widgets\ActiveShipmentsWidget;
use App\Filament\Portal\Widgets\FinancialSummaryWidget;
use App\Filament\Portal\Widgets\RecentDocumentsWidget;
use Filament\Pages\Dashboard;

class PortalDashboard extends Dashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-home';
    protected static ?int $navigationSort = -2;

    public function getWidgets(): array
    {
        $widgets = [
            ActiveShipmentsWidget::class,
        ];

        if (auth()->user()?->can('portal:view-financial-summary')) {
            $widgets[] = FinancialSummaryWidget::class;
        }

        $widgets[] = RecentDocumentsWidget::class;

        return $widgets;
    }

    public function getColumns(): int|string|array
    {
        return 2;
    }
}
