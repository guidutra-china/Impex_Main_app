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
                Section::make(__('forms.sections.company_information'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('forms.labels.company_name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('legal_name')
                            ->label(__('forms.labels.legal_name'))
                            ->maxLength(255)
                            ->helperText(__('forms.helpers.official_registered_name_for_legal_documents')),
                        TextInput::make('tax_number')
                            ->label(__('forms.labels.tax_number'))
                            ->maxLength(50)
                            ->helperText(__('forms.helpers.cnpj_vat_ein_etc')),
                        TextInput::make('email')
                            ->label(__('forms.labels.email'))
                            ->email()
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->label(__('forms.labels.phone'))
                            ->tel()
                            ->maxLength(50),
                        TextInput::make('website')
                            ->label(__('forms.labels.website'))
                            ->url()
                            ->maxLength(255)
                            ->prefix('https://'),
                    ])
                    ->columns(2)
                    ->columnSpan(['lg' => fn (?Company $record) => $record === null ? 3 : 2]),

                Section::make(__('forms.sections.status_roles'))
                    ->schema([
                        Select::make('status')
                            ->label(__('forms.labels.status'))
                            ->options(CompanyStatus::class)
                            ->required()
                            ->default(CompanyStatus::ACTIVE->value),
                        CheckboxList::make('roles')
                            ->label(__('forms.labels.roles'))
                            ->options(CompanyRole::class)
                            ->required()
                            ->helperText(__('forms.helpers.select_all_roles_this_company_plays_in_your_business'))
                            ->columns(1),
                    ])
                    ->columnSpan(['lg' => 1])
                    ->hidden(fn (?Company $record) => $record === null),

                Section::make(__('forms.sections.status_roles'))
                    ->schema([
                        Select::make('status')
                            ->label(__('forms.labels.status'))
                            ->options(CompanyStatus::class)
                            ->required()
                            ->default(CompanyStatus::ACTIVE->value),
                        CheckboxList::make('roles')
                            ->label(__('forms.labels.roles'))
                            ->options(CompanyRole::class)
                            ->required()
                            ->helperText(__('forms.helpers.select_all_roles_this_company_plays_in_your_business'))
                            ->columns(2),
                    ])
                    ->columnSpan(['lg' => 3])
                    ->visible(fn (?Company $record) => $record === null),

                Section::make(__('forms.sections.address'))
                    ->schema([
                        TextInput::make('address_street')
                            ->label(__('forms.labels.street'))
                            ->maxLength(255),
                        TextInput::make('address_number')
                            ->label(__('forms.labels.number'))
                            ->maxLength(20),
                        TextInput::make('address_complement')
                            ->label(__('forms.labels.complement'))
                            ->maxLength(255),
                        TextInput::make('address_city')
                            ->label(__('forms.labels.city'))
                            ->maxLength(255),
                        TextInput::make('address_state')
                            ->label(__('forms.labels.state_province'))
                            ->maxLength(255),
                        TextInput::make('address_zip')
                            ->label(__('forms.labels.zip_postal_code'))
                            ->maxLength(20),
                        TextInput::make('address_country')
                            ->label(__('forms.labels.country_code'))
                            ->maxLength(2)
                            ->placeholder(__('forms.placeholders.us'))
                            ->helperText(__('forms.helpers.iso_31661_alpha2_code_eg_us_cn_br_de')),
                    ])
                    ->columns(3)
                    ->columnSpan(['lg' => 3])
                    ->collapsible(),

                Section::make(__('forms.sections.contracted_importer'))
                    ->description(__('forms.descriptions.contracted_importer_details_for_conta_e_ordem'))
                    ->schema([
                        Textarea::make('contracted_importer_details')
                            ->label(__('forms.labels.contracted_importer_details'))
                            ->rows(6)
                            ->placeholder("Company Name\nCNPJ: 00.000.000/0001-00\nAddress: Rua Example, 123\nCity/State - CEP\nPhone: +55 11 0000-0000")
                            ->helperText(__('forms.helpers.enter_all_contracted_importer_details_as_they_should_appear'))
                            ->columnSpanFull(),
                    ])
                    ->columnSpan(['lg' => 3])
                    ->collapsible()
                    ->collapsed(),

                Section::make(__('forms.sections.notes'))
                    ->schema([
                        Textarea::make('notes')
                            ->label(__('forms.labels.internal_notes'))
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
