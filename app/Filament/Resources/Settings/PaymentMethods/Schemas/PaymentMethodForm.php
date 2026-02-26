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
                Section::make(__('forms.sections.payment_method_information'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('forms.labels.name'))
                            ->required()
                            ->maxLength(255)
                            ->placeholder(__('forms.placeholders.eg_paypal_business_bank_transfer_usd')),
                        Select::make('type')
                            ->label(__('forms.labels.type'))
                            ->options(PaymentMethodType::class)
                            ->required()
                            ->searchable(),
                        Select::make('bank_account_id')
                            ->label(__('forms.labels.linked_bank_account'))
                            ->relationship('bankAccount', 'account_name')
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->helperText(__('forms.helpers.optional_link_this_payment_method_to_a_specific_bank_account')),
                        Select::make('processing_time')
                            ->label(__('forms.labels.processing_time'))
                            ->options(ProcessingTime::class)
                            ->required()
                            ->default(ProcessingTime::IMMEDIATE->value),
                        Toggle::make('is_active')
                            ->label(__('forms.labels.active'))
                            ->default(true)
                            ->helperText(__('forms.helpers.inactive_payment_methods_will_not_be_available_for_selection')),
                    ])
                    ->columns(2),
                Section::make(__('forms.sections.fee_configuration'))
                    ->schema([
                        Select::make('fee_type')
                            ->label(__('forms.labels.fee_type'))
                            ->options(FeeType::class)
                            ->required()
                            ->default(FeeType::NONE->value)
                            ->live(),
                        TextInput::make('fixed_fee_amount')
                            ->label(__('forms.labels.fixed_fee'))
                            ->numeric()
                            ->default(0)
                            ->step(0.0001)
                            ->prefix('$')
                            ->formatStateUsing(fn ($state) => $state ? number_format(\App\Domain\Infrastructure\Support\Money::toMajor($state), 4, '.', '') : '0.0000')
                            ->dehydrateStateUsing(fn ($state) => \App\Domain\Infrastructure\Support\Money::toMinor($state ?? 0))
                            ->visible(fn ($get) => in_array($get('fee_type'), [
                                FeeType::FIXED->value,
                                FeeType::FIXED_PLUS_PERCENTAGE->value,
                                FeeType::FIXED,
                                FeeType::FIXED_PLUS_PERCENTAGE,
                            ])),
                        Select::make('fixed_fee_currency_id')
                            ->label(__('forms.labels.fee_currency'))
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
                            ->label(__('forms.labels.percentage_fee'))
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
                Section::make(__('forms.sections.additional_information'))
                    ->schema([
                        Textarea::make('notes')
                            ->label(__('forms.labels.notes'))
                            ->rows(3)
                            ->maxLength(65535)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
