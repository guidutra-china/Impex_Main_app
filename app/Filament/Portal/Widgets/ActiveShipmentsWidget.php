<?php

namespace App\Filament\Portal\Widgets;

use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class ActiveShipmentsWidget extends BaseWidget
{
    protected static ?string $heading = 'Active Shipments';
    protected int|string|array $columnSpan = 'full';
    protected static ?int $sort = 1;

    public function table(Table $table): Table
    {
        $tenant = Filament::getTenant();

        return $table
            ->query(
                Shipment::query()
                    ->where('company_id', $tenant?->getKey())
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
