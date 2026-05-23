<?php

namespace App\Filament\Resources\TradeFairs;

use App\Domain\TradeFairs\Models\TradeFair;
use App\Filament\Resources\TradeFairs\Pages\CreateTradeFair;
use App\Filament\Resources\TradeFairs\Pages\EditTradeFair;
use App\Filament\Resources\TradeFairs\Pages\ListTradeFairs;
use App\Filament\Resources\TradeFairs\Pages\ViewTradeFair;
use App\Filament\Resources\TradeFairs\RelationManagers\ProductsRelationManager;
use App\Filament\Resources\TradeFairs\RelationManagers\SuppliersRelationManager;
use App\Filament\Resources\TradeFairs\Schemas\TradeFairForm;
use App\Filament\Resources\TradeFairs\Schemas\TradeFairInfolist;
use App\Filament\Resources\TradeFairs\Tables\TradeFairsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class TradeFairResource extends Resource
{
    protected static ?string $model = TradeFair::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?int $navigationSort = 41;

    protected static ?string $slug = 'trade-fairs';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-companies') ?? false;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'location'];
    }

    public static function form(Schema $schema): Schema
    {
        return TradeFairForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return TradeFairInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TradeFairsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            SuppliersRelationManager::class,
            ProductsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTradeFairs::route('/'),
            'create' => CreateTradeFair::route('/create'),
            'view' => ViewTradeFair::route('/{record}'),
            'edit' => EditTradeFair::route('/{record}/edit'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.trade');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.resources.trade_fairs');
    }

    public static function getModelLabel(): string
    {
        return __('navigation.models.trade_fair');
    }

    public static function getPluralModelLabel(): string
    {
        return __('navigation.models.trade_fairs');
    }
}
