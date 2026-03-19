<?php

namespace App\Filament\SupplierPortal\Resources;

use App\Domain\Infrastructure\Support\Money;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use App\Filament\SupplierPortal\Resources\ShipmentResource\Pages;
use App\Filament\SupplierPortal\Resources\ShipmentResource\Widgets\SupplierShipmentStats;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ShipmentResource extends Resource
{
    protected static ?string $model = Shipment::class;
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-truck';
    protected static ?int $navigationSort = 42;
    protected static ?string $slug = 'shipments';
    protected static ?string $recordTitleAttribute = 'reference';
    protected static bool $isScopedToTenant = false;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('supplier-portal:view-shipments') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function ($query) {
                $tenant = Filament::getTenant();
                if ($tenant) {
                    $query->forSupplierCompany($tenant->getKey());
                }
            })
            ->columns([
                TextColumn::make('reference')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),
                TextColumn::make('bl_number')
                    ->label('B/L Number')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('transport_mode')
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('origin_port')
                    ->label('Origin')
                    ->placeholder('—'),
                TextColumn::make('destination_port')
                    ->label('Destination')
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
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(ShipmentStatus::class),
            ])
            ->recordUrl(fn (Shipment $record) => Pages\ViewShipment::getUrl(['record' => $record]))
            ->recordActions([
                \Filament\Actions\ViewAction::make()
                    ->url(fn (Shipment $record) => Pages\ViewShipment::getUrl(['record' => $record])),
            ])
            ->persistFiltersInSession()
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No shipments')
            ->emptyStateDescription('No shipments found for your company.')
            ->emptyStateIcon('heroicon-o-truck');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Shipment Details')
                ->schema([
                    TextEntry::make('reference')
                        ->copyable()
                        ->weight('bold'),
                    TextEntry::make('status')
                        ->badge(),
                    TextEntry::make('transport_mode')
                        ->badge()
                        ->placeholder('—'),
                    TextEntry::make('import_modality')
                        ->badge()
                        ->placeholder('—'),
                    TextEntry::make('carrier')
                        ->placeholder('—'),
                    TextEntry::make('freight_forwarder')
                        ->placeholder('—'),
                ])
                ->columns(3)
                ->columnSpanFull(),

            Section::make('Route & Schedule')
                ->schema([
                    TextEntry::make('origin_port')
                        ->label('Origin Port')
                        ->placeholder('—'),
                    TextEntry::make('destination_port')
                        ->label('Destination Port')
                        ->placeholder('—'),
                    TextEntry::make('etd')
                        ->label('ETD')
                        ->date('d/m/Y')
                        ->placeholder('—'),
                    TextEntry::make('eta')
                        ->label('ETA')
                        ->date('d/m/Y')
                        ->placeholder('—'),
                    TextEntry::make('actual_departure')
                        ->date('d/m/Y')
                        ->placeholder('—'),
                    TextEntry::make('actual_arrival')
                        ->date('d/m/Y')
                        ->placeholder('—'),
                ])
                ->columns(3)
                ->columnSpanFull(),

            Section::make('Transport Details')
                ->schema([
                    TextEntry::make('booking_number')
                        ->placeholder('—'),
                    TextEntry::make('bl_number')
                        ->label('B/L Number')
                        ->placeholder('—'),
                    TextEntry::make('container_number')
                        ->placeholder('—'),
                    TextEntry::make('vessel_name')
                        ->placeholder('—'),
                    TextEntry::make('voyage_number')
                        ->placeholder('—'),
                    TextEntry::make('container_type')
                        ->placeholder('—'),
                ])
                ->columns(3)
                ->collapsible()
                ->columnSpanFull(),

            Section::make('Weight & Volume')
                ->schema([
                    TextEntry::make('total_gross_weight')
                        ->label('Gross Weight (kg)')
                        ->placeholder('—'),
                    TextEntry::make('total_net_weight')
                        ->label('Net Weight (kg)')
                        ->placeholder('—'),
                    TextEntry::make('total_volume')
                        ->label('Volume (m³)')
                        ->placeholder('—'),
                    TextEntry::make('total_packages')
                        ->label('Packages')
                        ->placeholder('—'),
                ])
                ->columns(4)
                ->collapsible()
                ->columnSpanFull(),

            Section::make('Shipped Items')
                ->schema([
                    ViewEntry::make('shipped_items_table')
                        ->view('supplier-portal.infolists.shipment-items-table')
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),

            Section::make('References')
                ->schema([
                    TextEntry::make('purchase_order_references')
                        ->label('Purchase Orders')
                        ->getStateUsing(fn ($record) => $record->purchase_order_references ?: '—'),
                ])
                ->columns(2)
                ->collapsible()
                ->columnSpanFull(),

            Section::make('Notes')
                ->schema([
                    TextEntry::make('notes')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->collapsible()
                ->collapsed()
                ->columnSpanFull(),
        ]);
    }

    public static function getWidgets(): array
    {
        return [
            SupplierShipmentStats::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShipments::route('/'),
            'view' => Pages\ViewShipment::route('/{record}'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.resources.shipments');
    }

    public static function getModelLabel(): string
    {
        return __('navigation.models.shipment');
    }

    public static function getPluralModelLabel(): string
    {
        return __('navigation.models.shipments');
    }
}
