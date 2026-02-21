<?php

namespace App\Filament\Resources\Settings\BankAccounts\Schemas;

use App\Domain\Settings\Enums\BankAccountStatus;
use App\Domain\Settings\Enums\BankAccountType;
use Filament\Forms\Components\Section;
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
                Section::make('Account Information')
                    ->schema([
                        TextInput::make('account_name')
                            ->label('Account Name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g., HSBC Shanghai Business Account')
                            ->helperText('A descriptive name to identify this account.'),
                        TextInput::make('bank_name')
                            ->label('Bank Name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g., HSBC'),
                        Select::make('account_type')
                            ->label('Account Type')
                            ->options(BankAccountType::class)
                            ->required()
                            ->default(BankAccountType::BUSINESS),
                        Select::make('status')
                            ->label('Status')
                            ->options(BankAccountStatus::class)
                            ->required()
                            ->default(BankAccountStatus::ACTIVE),
                        Select::make('currency_id')
                            ->label('Currency')
                            ->relationship('currency', 'code')
                            ->required()
                            ->searchable()
                            ->preload(),
                    ])
                    ->columns(2),
                Section::make('Account Details')
                    ->schema([
                        TextInput::make('account_number')
                            ->label('Account Number')
                            ->maxLength(255),
                        TextInput::make('routing_number')
                            ->label('Routing Number')
                            ->maxLength(255),
                        TextInput::make('swift_code')
                            ->label('SWIFT / BIC Code')
                            ->maxLength(11)
                            ->placeholder('e.g., HSBCCNSH'),
                        TextInput::make('iban')
                            ->label('IBAN')
                            ->maxLength(34)
                            ->placeholder('e.g., GB29NWBK60161331926819'),
                    ])
                    ->columns(2),
                Section::make('Balances')
                    ->description('Balances are stored in minor units (cents). E.g., $1,000.00 = 100000.')
                    ->schema([
                        TextInput::make('current_balance')
                            ->label('Current Balance (minor units)')
                            ->numeric()
                            ->default(0)
                            ->helperText('Total balance including pending transactions.'),
                        TextInput::make('available_balance')
                            ->label('Available Balance (minor units)')
                            ->numeric()
                            ->default(0)
                            ->helperText('Balance available for use.'),
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
