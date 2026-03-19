<?php

namespace App\Filament\SupplierPortal\Widgets;

use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
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
            )
            ->columns([
                TextColumn::make('bl_number')
                    ->label('B/L Number')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->placeholder('—'),
                TextColumn::make('reference')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('transport_mode')
                    ->badge(),
                TextColumn::make('origin_port')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('destination_port')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('etd')
                    ->label('ETD')
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('eta')
                    ->label('ETA')
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('container_number')
                    ->label('Container')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(ShipmentStatus::class),
                SelectFilter::make('transport_mode')
                    ->options(\App\Domain\Logistics\Enums\TransportMode::class),
            ])
            ->defaultSort('eta')
            ->paginated(false)
            ->emptyStateHeading('No active shipments')
            ->emptyStateIcon('heroicon-o-truck');
    }
}
