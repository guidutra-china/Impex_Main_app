<?php

namespace App\Filament\Resources\Finance\Trips;

use App\Domain\Travel\Models\Trip;
use App\Filament\Resources\Finance\Trips\Pages\CreateTrip;
use App\Filament\Resources\Finance\Trips\Pages\EditTrip;
use App\Filament\Resources\Finance\Trips\Pages\ListTrips;
use App\Filament\Resources\Finance\Trips\Pages\ViewTrip;
use App\Filament\Resources\Finance\Trips\RelationManagers\ExpensesRelationManager;
use App\Filament\Resources\Finance\Trips\Schemas\TripForm;
use App\Filament\Resources\Finance\Trips\Schemas\TripInfolist;
use App\Filament\Resources\Finance\Trips\Tables\TripsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class TripResource extends Resource
{
    protected static ?string $model = Trip::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-paper-airplane';

    protected static ?int $navigationSort = 63;

    protected static ?string $slug = 'trips';

    protected static ?string $recordTitleAttribute = 'title';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-trips') ?? false;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'destination_city', 'notes'];
    }

    public static function form(Schema $schema): Schema
    {
        return TripForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return TripInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TripsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ExpensesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTrips::route('/'),
            'create' => CreateTrip::route('/create'),
            'view' => ViewTrip::route('/{record}'),
            'edit' => EditTrip::route('/{record}/edit'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.finance');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.resources.trips');
    }

    public static function getModelLabel(): string
    {
        return __('navigation.models.trip');
    }

    public static function getPluralModelLabel(): string
    {
        return __('navigation.models.trips');
    }
}
