<?php

namespace App\Filament\SupplierPortal\Resources\PurchaseOrderResource\Widgets;

use App\Domain\Infrastructure\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SupplierPurchaseOrderStats extends StatsOverviewWidget
{
    public $record;

    protected function getStats(): array
    {
        $po = $this->record;

        return [
            Stat::make('Status', $po->status->getLabel())
                ->color($po->status->getColor()),
            Stat::make('Total Value', ($po->currency_code ?? '') . ' ' . Money::format($po->total, 2)),
            Stat::make('Items', $po->items->count()),
        ];
    }
}
