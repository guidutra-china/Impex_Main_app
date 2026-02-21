<?php

namespace App\Filament\Resources\Settings\PaymentMethods\Schemas;

use App\Domain\Settings\Enums\FeeType;
use App\Domain\Settings\Enums\PaymentMethodType;
use App\Domain\Settings\Enums\ProcessingTime;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PaymentMethodForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Payment Method Information')
                    ->schema([
                        TextInput::make('name')
                            ->label('Name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g., PayPal Business, Bank Transfer USD'),
                        Select::make('type')
                            ->label('Type')
                            ->options(PaymentMethodType::class)
                            ->required()
                            ->searchable(),
                        Select::make('bank_account_id')
                            ->label('Linked Bank Account')
                            ->relationship('bankAccount', 'account_name')
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->helperText('Optional: Link this payment method to a specific bank account.'),
                        Select::make('processing_time')
                            ->label('Processing Time')
                            ->options(ProcessingTime::class)
                            ->required()
                            ->default(ProcessingTime::IMMEDIATE),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('Inactive payment methods will not be available for selection.'),
                    ])
                    ->columns(2),
                Section::make('Fee Configuration')
                    ->schema([
                        Select::make('fee_type')
                            ->label('Fee Type')
                            ->options(FeeType::class)
                            ->required()
                            ->default(FeeType::NONE)
                            ->live(),
                        TextInput::make('fixed_fee_amount')
                            ->label('Fixed Fee (in minor units / cents)')
                            ->numeric()
                            ->default(0)
                            ->helperText('Amount in cents (e.g., 500 for $5.00).')
                            ->visible(fn ($get) => in_array($get('fee_type'), [
                                FeeType::FIXED->value,
                                FeeType::FIXED_PLUS_PERCENTAGE->value,
                                FeeType::FIXED,
                                FeeType::FIXED_PLUS_PERCENTAGE,
                            ])),
                        Select::make('fixed_fee_currency_id')
                            ->label('Fee Currency')
                            ->relationship('fixedFeeCurrency', 'code')
                            ->searchable()
                            ->preload()
                            ->visible(fn ($get) => in_array($get('fee_type'), [
                                FeeType::FIXED->value,
                                FeeType::FIXED_PLUS_PERCENTAGE->value,
                                FeeType::FIXED,
                                FeeType::FIXED_PLUS_PERCENTAGE,
                            ])),
                        TextInput::make('percentage_fee')
                            ->label('Percentage Fee')
                            ->numeric()
                            ->default(0)
                            ->suffix('%')
                            ->minValue(0)
                            ->maxValue(100)
                            ->step(0.01)
                            ->visible(fn ($get) => in_array($get('fee_type'), [
                                FeeType::PERCENTAGE->value,
                                FeeType::FIXED_PLUS_PERCENTAGE->value,
                                FeeType::PERCENTAGE,
                                FeeType::FIXED_PLUS_PERCENTAGE,
                            ])),
                    ])
                    ->columns(2),
                Section::make('Additional Information')
                    ->schema([
                        Textarea::make('notes')
                            ->label('Notes')
                            ->rows(3)
                            ->maxLength(65535)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
