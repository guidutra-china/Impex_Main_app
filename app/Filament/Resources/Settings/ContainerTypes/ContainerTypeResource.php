<?php

namespace App\Filament\Resources\Settings\ContainerTypes;

use App\Domain\Settings\Models\ContainerType;
use App\Filament\Resources\Settings\ContainerTypes\Pages\CreateContainerType;
use App\Filament\Resources\Settings\ContainerTypes\Pages\EditContainerType;
use App\Filament\Resources\Settings\ContainerTypes\Pages\ListContainerTypes;
use App\Filament\Resources\Settings\ContainerTypes\Schemas\ContainerTypeForm;
use App\Filament\Resources\Settings\ContainerTypes\Tables\ContainerTypesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class ContainerTypeResource extends Resource
{
    protected static ?string $model = ContainerType::class;

    protected static UnitEnum|string|null $navigationGroup = 'Settings';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-cube';

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'Container Types';

    public static function form(Schema $schema): Schema
    {
        return ContainerTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ContainerTypesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContainerTypes::route('/'),
            'create' => CreateContainerType::route('/create'),
            'edit' => EditContainerType::route('/{record}/edit'),
        ];
    }
}
