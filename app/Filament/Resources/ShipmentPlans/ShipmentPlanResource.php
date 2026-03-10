<?php

namespace App\Filament\Resources\ShipmentPlans;

use App\Domain\Planning\Models\ShipmentPlan;
use App\Filament\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\ShipmentPlans\Pages\CreateShipmentPlan;
use App\Filament\Resources\ShipmentPlans\Pages\EditShipmentPlan;
use App\Filament\Resources\ShipmentPlans\Pages\ListShipmentPlans;
use App\Filament\Resources\ShipmentPlans\Pages\ViewShipmentPlan;
use App\Filament\Resources\ShipmentPlans\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\ShipmentPlans\RelationManagers\PaymentScheduleRelationManager;
use App\Filament\Resources\ShipmentPlans\Schemas\ShipmentPlanForm;
use App\Filament\Resources\ShipmentPlans\Schemas\ShipmentPlanInfolist;
use App\Filament\Resources\ShipmentPlans\Tables\ShipmentPlansTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class ShipmentPlanResource extends Resource
{
    protected static ?string $model = ShipmentPlan::class;
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-clipboard-document-check';
    protected static ?int $navigationSort = 47;
    protected static ?string $slug = 'shipment-plans';
    protected static ?string $recordTitleAttribute = 'reference';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-shipment-plans') ?? false;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['reference', 'supplierCompany.name'];
    }

    public static function form(Schema $schema): Schema
    {
        return ShipmentPlanForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ShipmentPlanInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ShipmentPlansTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
            PaymentScheduleRelationManager::class,
            DocumentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListShipmentPlans::route('/'),
            'create' => CreateShipmentPlan::route('/create'),
            'view' => ViewShipmentPlan::route('/{record}'),
            'edit' => EditShipmentPlan::route('/{record}/edit'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.resources.shipment_plans');
    }

    public static function getModelLabel(): string
    {
        return __('navigation.models.shipment_plan');
    }

    public static function getPluralModelLabel(): string
    {
        return __('navigation.models.shipment_plans');
    }
}
