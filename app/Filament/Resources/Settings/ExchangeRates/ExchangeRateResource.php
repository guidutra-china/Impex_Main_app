<?php

namespace App\Filament\Resources\Settings\ExchangeRates;

use App\Domain\Settings\Models\ExchangeRate;
use App\Filament\Resources\Settings\ExchangeRates\Pages\CreateExchangeRate;
use App\Filament\Resources\Settings\ExchangeRates\Pages\EditExchangeRate;
use App\Filament\Resources\Settings\ExchangeRates\Pages\ListExchangeRates;
use App\Filament\Resources\Settings\ExchangeRates\Schemas\ExchangeRateForm;
use App\Filament\Resources\Settings\ExchangeRates\Tables\ExchangeRatesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class ExchangeRateResource extends Resource
{
    protected static ?string $model = ExchangeRate::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-settings') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return ExchangeRateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ExchangeRatesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExchangeRates::route('/'),
            'create' => CreateExchangeRate::route('/create'),
            'edit' => EditExchangeRate::route('/{record}/edit'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.resources.exchange_rates');
    }
}
