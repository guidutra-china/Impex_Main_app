<?php

namespace App\Filament\Resources\Settings\Currencies;

use App\Domain\Settings\Models\Currency;
use App\Filament\Resources\Settings\Currencies\Pages\CreateCurrency;
use App\Filament\Resources\Settings\Currencies\Pages\EditCurrency;
use App\Filament\Resources\Settings\Currencies\Pages\ListCurrencies;
use App\Filament\Resources\Settings\Currencies\Schemas\CurrencyForm;
use App\Filament\Resources\Settings\Currencies\Tables\CurrenciesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class CurrencyResource extends Resource
{
    protected static ?string $model = Currency::class;

    protected static UnitEnum|string|null $navigationGroup = 'Settings';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Currencies';

    public static function form(Schema $schema): Schema
    {
        return CurrencyForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CurrenciesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCurrencies::route('/'),
            'create' => CreateCurrency::route('/create'),
            'edit' => EditCurrency::route('/{record}/edit'),
        ];
    }
}
