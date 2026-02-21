<?php

namespace App\Filament\Resources\Settings\Currencies\Schemas;

use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CurrencyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Currency Details')
                    ->schema([
                        TextInput::make('code')
                            ->label('ISO Code')
                            ->required()
                            ->maxLength(3)
                            ->minLength(3)
                            ->unique(ignoreRecord: true)
                            ->placeholder('USD')
                            ->helperText('ISO 4217 currency code (e.g., USD, EUR, CNY).'),
                        TextInput::make('name')
                            ->label('Name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('US Dollar'),
                        TextInput::make('name_plural')
                            ->label('Plural Name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('US Dollars'),
                        TextInput::make('symbol')
                            ->label('Symbol')
                            ->required()
                            ->maxLength(10)
                            ->placeholder('$'),
                        TextInput::make('decimal_places')
                            ->label('Decimal Places')
                            ->numeric()
                            ->required()
                            ->default(2)
                            ->minValue(0)
                            ->maxValue(8)
                            ->helperText('Number of decimal places for this currency.'),
                    ])
                    ->columns(3),
                Section::make('Status')
                    ->schema([
                        Toggle::make('is_base')
                            ->label('Base Currency')
                            ->helperText('Only one currency can be the base. Setting this will unset the current base currency.')
                            ->default(false),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->helperText('Inactive currencies will not be available for selection.')
                            ->default(true),
                    ])
                    ->columns(2),
            ]);
    }
}
