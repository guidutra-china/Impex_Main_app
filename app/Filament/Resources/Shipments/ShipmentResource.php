<?php

namespace App\Filament\Resources\Shipments;

use App\Domain\Logistics\Models\Shipment;
use App\Filament\RelationManagers\AdditionalCostsRelationManager;
use App\Filament\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Shipments\Pages\CreateShipment;
use App\Filament\Resources\Shipments\Pages\EditShipment;
use App\Filament\Resources\Shipments\Pages\ListShipments;
use App\Filament\Resources\Shipments\Pages\ViewShipment;
use App\Filament\Resources\Shipments\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\Shipments\RelationManagers\PackingListRelationManager;
use App\Filament\Resources\Shipments\Schemas\ShipmentForm;
use App\Filament\Resources\Shipments\Schemas\ShipmentInfolist;
use App\Filament\Resources\Shipments\Tables\ShipmentsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class ShipmentResource extends Resource
{
    protected static ?string $model = Shipment::class;
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-truck';
    protected static ?int $navigationSort = 6;
    protected static ?string $slug = 'shipments';
    protected static ?string $recordTitleAttribute = 'reference';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-shipments') ?? false;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['reference', 'company.name', 'bl_number', 'container_number'];
    }

    public static function form(Schema $schema): Schema
    {
        return ShipmentForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ShipmentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ShipmentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
            PackingListRelationManager::class,
            AdditionalCostsRelationManager::class,
            DocumentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListShipments::route('/'),
            'create' => CreateShipment::route('/create'),
            'view' => ViewShipment::route('/{record}'),
            'edit' => EditShipment::route('/{record}/edit'),
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
