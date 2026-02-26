<?php

namespace App\Filament\Resources\Settings\BankAccounts\Schemas;

use App\Domain\Settings\Enums\BankAccountStatus;
use App\Domain\Settings\Enums\BankAccountType;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class BankAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('forms.sections.account_information'))
                    ->schema([
                        TextInput::make('account_name')
                            ->label(__('forms.labels.account_name'))
                            ->required()
                            ->maxLength(255)
                            ->placeholder(__('forms.placeholders.eg_hsbc_shanghai_business_account'))
                            ->helperText(__('forms.helpers.a_descriptive_name_to_identify_this_account')),
                        TextInput::make('bank_name')
                            ->label(__('forms.labels.bank_name'))
                            ->required()
                            ->maxLength(255)
                            ->placeholder(__('forms.placeholders.eg_hsbc')),
                        Select::make('account_type')
                            ->label(__('forms.labels.account_type'))
                            ->options(BankAccountType::class)
                            ->required()
                            ->default(BankAccountType::BUSINESS->value),
                        Select::make('status')
                            ->label(__('forms.labels.status'))
                            ->options(BankAccountStatus::class)
                            ->required()
                            ->default(BankAccountStatus::ACTIVE->value),
                        Select::make('currency_id')
                            ->label(__('forms.labels.currency'))
                            ->relationship('currency', 'code')
                            ->required()
                            ->searchable()
                            ->preload(),
                    ])
                    ->columns(2),
                Section::make(__('forms.sections.account_details'))
                    ->schema([
                        TextInput::make('account_number')
                            ->label(__('forms.labels.account_number'))
                            ->maxLength(255),
                        TextInput::make('routing_number')
                            ->label(__('forms.labels.routing_number'))
                            ->maxLength(255),
                        TextInput::make('swift_code')
                            ->label(__('forms.labels.swift_bic_code'))
                            ->maxLength(11)
                            ->placeholder(__('forms.placeholders.eg_hsbccnsh')),
                        TextInput::make('iban')
                            ->label(__('forms.labels.iban'))
                            ->maxLength(34)
                            ->placeholder(__('forms.placeholders.eg_gb29nwbk60161331926819')),
                    ])
                    ->columns(2),
                Section::make(__('forms.sections.balances'))
                    ->description(__('forms.descriptions.balances_are_stored_in_minor_units_cents_eg_100000_100000'))
                    ->schema([
                        TextInput::make('current_balance')
                            ->label(__('forms.labels.current_balance_minor_units'))
                            ->numeric()
                            ->default(0)
                            ->helperText(__('forms.helpers.total_balance_including_pending_transactions')),
                        TextInput::make('available_balance')
                            ->label(__('forms.labels.available_balance_minor_units'))
                            ->numeric()
                            ->default(0)
                            ->helperText(__('forms.helpers.balance_available_for_use')),
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
