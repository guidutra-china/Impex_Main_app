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
                Section::make('Currency Pair')
                    ->schema([
                        Select::make('base_currency_id')
                            ->label('Base Currency')
                            ->relationship('baseCurrency', 'code')
                            ->required()
                            ->searchable()
                            ->preload(),
                        Select::make('target_currency_id')
                            ->label('Target Currency')
                            ->relationship('targetCurrency', 'code')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->different('base_currency_id'),
                        DatePicker::make('date')
                            ->label('Rate Date')
                            ->required()
                            ->default(now()),
                    ])
                    ->columns(3),
                Section::make('Rate')
                    ->schema([
                        TextInput::make('rate')
                            ->label('Rate')
                            ->required()
                            ->numeric()
                            ->minValue(0.00000001)
                            ->step(0.00000001)
                            ->helperText('How many units of target currency per 1 unit of base currency.'),
                        TextInput::make('inverse_rate')
                            ->label('Inverse Rate')
                            ->numeric()
                            ->minValue(0.00000001)
                            ->step(0.00000001)
                            ->helperText('Automatically calculated if left empty.')
                            ->placeholder('Auto-calculated'),
                    ])
                    ->columns(2),
                Section::make('Source & Status')
                    ->schema([
                        Select::make('source')
                            ->label('Source')
                            ->options(ExchangeRateSource::class)
                            ->required()
                            ->default(ExchangeRateSource::MANUAL->value),
                        TextInput::make('source_name')
                            ->label('Source Name')
                            ->maxLength(255)
                            ->placeholder('e.g., Central Bank, Reuters'),
                        Select::make('status')
                            ->label('Status')
                            ->options(ExchangeRateStatus::class)
                            ->required()
                            ->default(ExchangeRateStatus::APPROVED->value),
                        Textarea::make('notes')
                            ->label('Notes')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(3),
            ]);
    }
}
