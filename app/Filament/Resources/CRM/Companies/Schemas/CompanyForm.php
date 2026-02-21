<?php

namespace App\Filament\Resources\CRM\Companies\Schemas;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Enums\CompanyStatus;
use App\Domain\CRM\Models\Company;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CompanyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Company Information')
                    ->schema([
                        TextInput::make('name')
                            ->label('Company Name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('legal_name')
                            ->label('Legal Name')
                            ->maxLength(255)
                            ->helperText('Official registered name for legal documents.'),
                        TextInput::make('tax_number')
                            ->label('Tax Number')
                            ->maxLength(50)
                            ->helperText('CNPJ, VAT, EIN, etc.'),
                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->label('Phone')
                            ->tel()
                            ->maxLength(50),
                        TextInput::make('website')
                            ->label('Website')
                            ->url()
                            ->maxLength(255)
                            ->prefix('https://'),
                    ])
                    ->columns(2)
                    ->columnSpan(['lg' => fn (?Company $record) => $record === null ? 3 : 2]),

                Section::make('Status & Roles')
                    ->schema([
                        Select::make('status')
                            ->label('Status')
                            ->options(CompanyStatus::class)
                            ->required()
                            ->default(CompanyStatus::ACTIVE),
                        CheckboxList::make('roles')
                            ->label('Roles')
                            ->options(CompanyRole::class)
                            ->required()
                            ->helperText('Select all roles this company plays in your business.')
                            ->columns(1),
                    ])
                    ->columnSpan(['lg' => 1])
                    ->hidden(fn (?Company $record) => $record === null),

                Section::make('Status & Roles')
                    ->schema([
                        Select::make('status')
                            ->label('Status')
                            ->options(CompanyStatus::class)
                            ->required()
                            ->default(CompanyStatus::ACTIVE),
                        CheckboxList::make('roles')
                            ->label('Roles')
                            ->options(CompanyRole::class)
                            ->required()
                            ->helperText('Select all roles this company plays in your business.')
                            ->columns(2),
                    ])
                    ->columnSpan(['lg' => 3])
                    ->visible(fn (?Company $record) => $record === null),

                Section::make('Address')
                    ->schema([
                        TextInput::make('address_street')
                            ->label('Street')
                            ->maxLength(255),
                        TextInput::make('address_number')
                            ->label('Number')
                            ->maxLength(20),
                        TextInput::make('address_complement')
                            ->label('Complement')
                            ->maxLength(255),
                        TextInput::make('address_city')
                            ->label('City')
                            ->maxLength(255),
                        TextInput::make('address_state')
                            ->label('State / Province')
                            ->maxLength(255),
                        TextInput::make('address_zip')
                            ->label('ZIP / Postal Code')
                            ->maxLength(20),
                        TextInput::make('address_country')
                            ->label('Country Code')
                            ->maxLength(2)
                            ->placeholder('US')
                            ->helperText('ISO 3166-1 alpha-2 code (e.g., US, CN, BR, DE).'),
                    ])
                    ->columns(3)
                    ->columnSpan(['lg' => 3])
                    ->collapsible(),

                Section::make('Notes')
                    ->schema([
                        Textarea::make('notes')
                            ->label('Internal Notes')
                            ->rows(3)
                            ->maxLength(5000),
                    ])
                    ->columnSpan(['lg' => 3])
                    ->collapsible()
                    ->collapsed(),
            ])
            ->columns(3);
    }
}
