<?php

namespace App\Filament\Portal\Resources;

use App\Domain\Infrastructure\Support\Money;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use App\Filament\Portal\Resources\ShipmentResource\Pages;
use App\Filament\Portal\Resources\ShipmentResource\Widgets\PortalShipmentOverview;
use App\Filament\Portal\Widgets\ShipmentsListStats;
use Filament\Infolists\Components\TextEntry;
use BackedEnum;
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
    protected static ?int $navigationSort = 1;
    protected static ?string $slug = 'shipments';
    protected static ?string $recordTitleAttribute = 'reference';
    protected static ?string $tenantOwnershipRelationshipName = 'company';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('portal:view-shipments') ?? false;
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
            ->columns([
                TextColumn::make('reference')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('transport_mode')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('bl_number')
                    ->label('B/L')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('container_number')
                    ->label('Container')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('origin_port')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('destination_port')
                    ->placeholder('—')
                    ->toggleable(),
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
                TextColumn::make('items_count')
                    ->label('Items')
                    ->counts('items')
                    ->alignCenter()
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
            Section::make('Shipment Information')
                ->schema([
                    TextEntry::make('reference')
                        ->copyable()
                        ->weight('bold'),
                    TextEntry::make('status')
                        ->badge(),
                    TextEntry::make('transport_mode')
                        ->badge()
                        ->placeholder('—'),
                    TextEntry::make('container_type')
                        ->badge()
                        ->placeholder('—'),
                    TextEntry::make('currency_code')
                        ->label('Currency')
                        ->badge()
                        ->color('gray')
                        ->placeholder('—'),
                    TextEntry::make('issue_date')
                        ->label('Issue Date')
                        ->date('d/m/Y')
                        ->placeholder('—'),
                ])
                ->columns(3)
                ->columnSpanFull(),

            Section::make('Route & Transport')
                ->schema([
                    TextEntry::make('origin_port')
                        ->label('Port of Loading')
                        ->placeholder('—'),
                    TextEntry::make('destination_port')
                        ->label('Port of Destination')
                        ->placeholder('—'),
                    TextEntry::make('vessel_name')
                        ->placeholder('—'),
                    TextEntry::make('bl_number')
                        ->label('B/L Number')
                        ->copyable()
                        ->placeholder('—'),
                    TextEntry::make('container_number')
                        ->copyable()
                        ->placeholder('—'),
                    TextEntry::make('voyage_number')
                        ->placeholder('—'),
                ])
                ->columns(3)
                ->collapsible()
                ->columnSpanFull(),

            Section::make('Dates')
                ->schema([
                    TextEntry::make('etd')
                        ->label('ETD (Estimated Departure)')
                        ->date('d/m/Y')
                        ->placeholder('—'),
                    TextEntry::make('eta')
                        ->label('ETA (Estimated Arrival)')
                        ->date('d/m/Y')
                        ->placeholder('—'),
                    TextEntry::make('actual_departure')
                        ->date('d/m/Y')
                        ->placeholder('—'),
                    TextEntry::make('actual_arrival')
                        ->date('d/m/Y')
                        ->placeholder('—'),
                ])
                ->columns(4)
                ->collapsible()
                ->columnSpanFull(),

            Section::make('Weight & Volume')
                ->schema([
                    TextEntry::make('total_gross_weight')
                        ->label('Gross Weight')
                        ->suffix(' kg')
                        ->placeholder('—'),
                    TextEntry::make('total_net_weight')
                        ->label('Net Weight')
                        ->suffix(' kg')
                        ->placeholder('—'),
                    TextEntry::make('total_volume')
                        ->label('Volume')
                        ->suffix(' CBM')
                        ->placeholder('—'),
                    TextEntry::make('total_packages')
                        ->label('Packages')
                        ->placeholder('—'),
                ])
                ->columns(4)
                ->collapsible()
                ->columnSpanFull(),

            Section::make('References')
                ->schema([
                    TextEntry::make('proforma_invoice_references')
                        ->label('Proforma Invoices')
                        ->placeholder('—'),
                    TextEntry::make('purchase_order_references')
                        ->label('Purchase Orders')
                        ->placeholder('—'),
                    TextEntry::make('total_value')
                        ->label('Total Value')
                        ->formatStateUsing(fn ($state, $record) => ($record->currency_code ?? '') . ' ' . Money::format($state))
                        ->weight('bold')
                        ->visible(fn () => auth()->user()?->can('portal:view-financial-summary')),
                ])
                ->columns(3)
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
            PortalShipmentOverview::class,
            ShipmentsListStats::class,
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
