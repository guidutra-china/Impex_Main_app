<?php

namespace App\Filament\Resources\ProductionSchedules;

use App\Domain\Planning\Models\ProductionSchedule;
use App\Filament\Resources\ProductionSchedules\Pages\CreateProductionSchedule;
use App\Filament\Resources\ProductionSchedules\Pages\EditProductionSchedule;
use App\Filament\Resources\ProductionSchedules\Pages\ListProductionSchedules;
use App\Filament\Resources\ProductionSchedules\Pages\ViewProductionSchedule;
use App\Filament\Resources\ProductionSchedules\RelationManagers\EntriesRelationManager;
use App\Filament\Resources\ProductionSchedules\Schemas\ProductionScheduleForm;
use App\Filament\Resources\ProductionSchedules\Schemas\ProductionScheduleInfolist;
use App\Filament\Resources\ProductionSchedules\Tables\ProductionSchedulesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class ProductionScheduleResource extends Resource
{
    protected static ?string $model = ProductionSchedule::class;
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-calendar-days';
    protected static ?int $navigationSort = 5;
    protected static ?string $slug = 'production-schedules';
    protected static ?string $recordTitleAttribute = 'reference';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-production-schedules') ?? false;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['reference', 'proformaInvoice.reference', 'proformaInvoice.company.name'];
    }

    public static function form(Schema $schema): Schema
    {
        return ProductionScheduleForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ProductionScheduleInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductionSchedulesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            EntriesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductionSchedules::route('/'),
            'create' => CreateProductionSchedule::route('/create'),
            'view' => ViewProductionSchedule::route('/{record}'),
            'edit' => EditProductionSchedule::route('/{record}/edit'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.resources.production_schedules');
    }

    public static function getModelLabel(): string
    {
        return __('navigation.models.production_schedule');
    }

    public static function getPluralModelLabel(): string
    {
        return __('navigation.models.production_schedules');
    }
}
