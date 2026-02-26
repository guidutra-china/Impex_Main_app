<?php

namespace App\Filament\Resources\Settings\ExchangeRates\Schemas;

use App\Domain\Settings\Enums\ExchangeRateSource;
use App\Domain\Settings\Enums\ExchangeRateStatus;
use Filament\Forms\Components\DatePicker;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ExchangeRateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('forms.sections.currency_pair'))
                    ->schema([
                        Select::make('base_currency_id')
                            ->label(__('forms.labels.base_currency'))
                            ->relationship('baseCurrency', 'code')
                            ->required()
                            ->searchable()
                            ->preload(),
                        Select::make('target_currency_id')
                            ->label(__('forms.labels.target_currency'))
                            ->relationship('targetCurrency', 'code')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->different('base_currency_id'),
                        DatePicker::make('date')
                            ->label(__('forms.labels.rate_date'))
                            ->required()
                            ->default(now()),
                    ])
                    ->columns(3),
                Section::make(__('forms.sections.rate'))
                    ->schema([
                        TextInput::make('rate')
                            ->label(__('forms.labels.rate'))
                            ->required()
                            ->numeric()
                            ->minValue(0.00000001)
                            ->step(0.00000001)
                            ->helperText(__('forms.helpers.how_many_units_of_target_currency_per_1_unit_of_base')),
                        TextInput::make('inverse_rate')
                            ->label(__('forms.labels.inverse_rate'))
                            ->numeric()
                            ->minValue(0.00000001)
                            ->step(0.00000001)
                            ->helperText(__('forms.helpers.automatically_calculated_if_left_empty'))
                            ->placeholder(__('forms.placeholders.autocalculated')),
                    ])
                    ->columns(2),
                Section::make(__('forms.sections.source_status'))
                    ->schema([
                        Select::make('source')
                            ->label(__('forms.labels.source'))
                            ->options(ExchangeRateSource::class)
                            ->required()
                            ->default(ExchangeRateSource::MANUAL->value),
                        TextInput::make('source_name')
                            ->label(__('forms.labels.source_name'))
                            ->maxLength(255)
                            ->placeholder(__('forms.placeholders.eg_central_bank_reuters')),
                        Select::make('status')
                            ->label(__('forms.labels.status'))
                            ->options(ExchangeRateStatus::class)
                            ->required()
                            ->default(ExchangeRateStatus::APPROVED->value),
                        Textarea::make('notes')
                            ->label(__('forms.labels.notes'))
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(3),
            ]);
    }
}
