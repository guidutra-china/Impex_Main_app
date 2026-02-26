<?php

namespace App\Filament\Resources\Settings\Currencies\Schemas;

use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CurrencyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('forms.sections.currency_details'))
                    ->schema([
                        TextInput::make('code')
                            ->label(__('forms.labels.iso_code'))
                            ->required()
                            ->maxLength(3)
                            ->minLength(3)
                            ->unique(ignoreRecord: true)
                            ->placeholder(__('forms.placeholders.usd'))
                            ->helperText(__('forms.helpers.iso_4217_currency_code_eg_usd_eur_cny')),
                        TextInput::make('name')
                            ->label(__('forms.labels.name'))
                            ->required()
                            ->maxLength(255)
                            ->placeholder(__('forms.placeholders.us_dollar')),
                        TextInput::make('name_plural')
                            ->label(__('forms.labels.plural_name'))
                            ->required()
                            ->maxLength(255)
                            ->placeholder(__('forms.placeholders.us_dollars')),
                        TextInput::make('symbol')
                            ->label(__('forms.labels.symbol'))
                            ->required()
                            ->maxLength(10)
                            ->placeholder('$'),
                        TextInput::make('decimal_places')
                            ->label(__('forms.labels.decimal_places'))
                            ->numeric()
                            ->required()
                            ->default(2)
                            ->minValue(0)
                            ->maxValue(8)
                            ->helperText(__('forms.helpers.number_of_decimal_places_for_this_currency')),
                    ])
                    ->columns(3),
                Section::make(__('forms.sections.status'))
                    ->schema([
                        Toggle::make('is_base')
                            ->label(__('forms.labels.base_currency'))
                            ->helperText(__('forms.helpers.only_one_currency_can_be_the_base_setting_this_will_unset'))
                            ->default(false),
                        Toggle::make('is_active')
                            ->label(__('forms.labels.active'))
                            ->helperText(__('forms.helpers.inactive_currencies_will_not_be_available_for_selection'))
                            ->default(true),
                    ])
                    ->columns(2),
            ]);
    }
}
