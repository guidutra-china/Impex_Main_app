<?php

namespace App\Filament\Resources\Settings\PaymentTerms\Schemas;

use App\Domain\Settings\Enums\CalculationBase;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PaymentTermForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Payment Term Information')
                    ->schema([
                        TextInput::make('name')
                            ->label('Name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g., 30/70 - 30% deposit, 70% before shipment'),
                        Textarea::make('description')
                            ->label('Description')
                            ->rows(3)
                            ->maxLength(65535)
                            ->columnSpanFull(),
                        Toggle::make('is_default')
                            ->label('Default Payment Term')
                            ->helperText('Setting this will unset the current default.'),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ])
                    ->columns(2),
                Section::make('Payment Stages')
                    ->description('Define the payment stages. The total percentage across all stages must equal 100%.')
                    ->schema([
                        Repeater::make('stages')
                            ->relationship()
                            ->schema([
                                TextInput::make('percentage')
                                    ->label('Percentage')
                                    ->required()
                                    ->numeric()
                                    ->suffix('%')
                                    ->minValue(1)
                                    ->maxValue(100),
                                TextInput::make('days')
                                    ->label('Days')
                                    ->required()
                                    ->numeric()
                                    ->default(0)
                                    ->minValue(0)
                                    ->helperText('Days after the calculation base date.'),
                                Select::make('calculation_base')
                                    ->label('Calculation Base')
                                    ->options(CalculationBase::class)
                                    ->required()
                                    ->default(CalculationBase::ORDER_DATE->value),
                            ])
                            ->columns(3)
                            ->orderColumn('sort_order')
                            ->reorderable()
                            ->addActionLabel('Add Stage')
                            ->defaultItems(1)
                            ->minItems(1)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
