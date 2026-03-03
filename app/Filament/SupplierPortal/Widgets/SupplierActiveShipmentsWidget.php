<?php

namespace App\Filament\SupplierPortal\Widgets;

use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class SupplierActiveShipmentsWidget extends BaseWidget
{
    protected int|string|array $columnSpan = 'full';
    protected static ?int $sort = 2;

    public function getHeading(): string
    {
        return 'Active Shipments';
    }

    public function table(Table $table): Table
    {
        $tenant = Filament::getTenant();

        return $table
            ->query(
                Shipment::query()
                    ->forSupplierCompany($tenant?->getKey())
                    ->whereNotIn('status', [
                        ShipmentStatus::ARRIVED,
                        ShipmentStatus::CANCELLED,
                    ])
                    ->orderBy('eta')
            )
            ->columns([
                TextColumn::make('reference')
                    ->weight('bold')
                    ->copyable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('transport_mode')
                    ->badge(),
                TextColumn::make('origin_port')
                    ->placeholder('—'),
                TextColumn::make('destination_port')
                    ->placeholder('—'),
                TextColumn::make('etd')
                    ->label('ETD')
                    ->date('d/m/Y')
                    ->placeholder('—'),
                TextColumn::make('eta')
                    ->label('ETA')
                    ->date('d/m/Y')
                    ->placeholder('—'),
                TextColumn::make('container_number')
                    ->label('Container')
                    ->copyable()
                    ->placeholder('—'),
            ])
            ->paginated(false)
            ->emptyStateHeading('No active shipments')
            ->emptyStateIcon('heroicon-o-truck');
    }
}
