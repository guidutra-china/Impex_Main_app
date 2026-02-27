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
    protected int|string|array $columnSpan = 'full';
    protected static ?int $sort = 1;

    public function getHeading(): string
    {
        return __('widgets.portal.active_shipments');
    }

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
                    ->label(__('widgets.portal.etd'))
                    ->date('d/m/Y')
                    ->placeholder('—'),
                TextColumn::make('eta')
                    ->label(__('widgets.portal.eta'))
                    ->date('d/m/Y')
                    ->placeholder('—'),
                TextColumn::make('container_number')
                    ->label(__('widgets.portal.container'))
                    ->copyable()
                    ->placeholder('—'),
            ])
            ->paginated(false)
            ->emptyStateHeading(__('widgets.portal.no_active_shipments'))
            ->emptyStateIcon('heroicon-o-truck');
    }
}
