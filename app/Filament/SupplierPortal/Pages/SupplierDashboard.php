<?php

namespace App\Filament\SupplierPortal\Pages;

use App\Filament\SupplierPortal\Widgets\SupplierOverviewWidget;
use App\Filament\SupplierPortal\Widgets\SupplierActiveShipmentsWidget;
use App\Filament\SupplierPortal\Widgets\SupplierRecentPurchaseOrdersWidget;
use BackedEnum;
use Filament\Pages\Dashboard;

class SupplierDashboard extends Dashboard
{
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-home';
    protected static ?int $navigationSort = -2;

    public function getWidgets(): array
    {
        return [
            SupplierOverviewWidget::class,
            SupplierActiveShipmentsWidget::class,
            SupplierRecentPurchaseOrdersWidget::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 2;
    }
}
