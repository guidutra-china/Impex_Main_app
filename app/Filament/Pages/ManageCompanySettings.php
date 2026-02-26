<?php

namespace App\Filament\Pages;

use App\Domain\Settings\DataTransferObjects\CompanySettings;
use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use BackedEnum;
use UnitEnum;

class ManageCompanySettings extends SettingsPage
{
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string $settings = CompanySettings::class;

    protected static UnitEnum|string|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Company Settings';

    protected static ?string $title = 'Company Settings';

    protected static ?int $navigationSort = 0;

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Settings')
                    ->tabs([
                        Tabs\Tab::make(__('forms.tabs.company_information'))
                            ->icon('heroicon-o-building-office')
                            ->schema([
                                Section::make(__('forms.sections.general'))
                                    ->schema([
                                        TextInput::make('company_name')
                                            ->label(__('forms.labels.company_name'))
                                            ->required()
                                            ->maxLength(255),
                                        FileUpload::make('logo_path')
                                            ->label(__('forms.labels.company_logo'))
                                            ->image()
                                            ->directory('logos')
                                            ->disk('public')
                                            ->maxSize(2048)
                                            ->columnSpanFull(),
                                    ])
                                    ->columns(2),
                                Section::make(__('forms.sections.address'))
                                    ->schema([
                                        TextInput::make('address')
                                            ->label(__('forms.labels.address'))
                                            ->maxLength(500)
                                            ->columnSpanFull(),
                                        TextInput::make('city')
                                            ->label(__('forms.labels.city'))
                                            ->maxLength(255),
                                        TextInput::make('state')
                                            ->label(__('forms.labels.state_province'))
                                            ->maxLength(255),
                                        TextInput::make('zip_code')
                                            ->label(__('forms.labels.zip_postal_code_2'))
                                            ->maxLength(20),
                                        TextInput::make('country')
                                            ->label(__('forms.labels.country'))
                                            ->maxLength(255),
                                    ])
                                    ->columns(2),
                                Section::make(__('forms.sections.contact'))
                                    ->schema([
                                        TextInput::make('phone')
                                            ->label(__('forms.labels.phone'))
                                            ->tel()
                                            ->maxLength(50),
                                        TextInput::make('email')
                                            ->label(__('forms.labels.email'))
                                            ->email()
                                            ->maxLength(255),
                                        TextInput::make('website')
                                            ->label(__('forms.labels.website'))
                                            ->url()
                                            ->maxLength(255),
                                    ])
                                    ->columns(3),
                                Section::make(__('forms.sections.legal'))
                                    ->schema([
                                        TextInput::make('tax_id')
                                            ->label(__('forms.labels.tax_id'))
                                            ->maxLength(100),
                                        TextInput::make('registration_number')
                                            ->label(__('forms.labels.registration_number'))
                                            ->maxLength(100),
                                    ])
                                    ->columns(2),
                            ]),
                        Tabs\Tab::make(__('forms.tabs.document_settings'))
                            ->icon('heroicon-o-document-text')
                            ->schema([
                                Section::make(__('forms.sections.document_prefixes'))
                                    ->description(__('forms.descriptions.prefixes_used_for_autogenerating_document_numbers'))
                                    ->schema([
                                        TextInput::make('invoice_prefix')
                                            ->label(__('forms.labels.invoice_prefix'))
                                            ->required()
                                            ->maxLength(10),
                                        TextInput::make('quote_prefix')
                                            ->label(__('forms.labels.quote_prefix'))
                                            ->required()
                                            ->maxLength(10),
                                        TextInput::make('po_prefix')
                                            ->label(__('forms.labels.purchase_order_prefix'))
                                            ->required()
                                            ->maxLength(10),
                                        TextInput::make('packing_list_prefix')
                                            ->label(__('forms.labels.packing_list_prefix'))
                                            ->required()
                                            ->maxLength(10),
                                        TextInput::make('commercial_invoice_prefix')
                                            ->label(__('forms.labels.commercial_invoice_prefix'))
                                            ->required()
                                            ->maxLength(10),
                                    ])
                                    ->columns(3),
                                Section::make(__('forms.sections.default_texts'))
                                    ->schema([
                                        Textarea::make('footer_text')
                                            ->label(__('forms.labels.document_footer_text'))
                                            ->rows(3)
                                            ->columnSpanFull(),
                                        Textarea::make('rfq_default_instructions')
                                            ->label(__('forms.labels.default_rfq_instructions'))
                                            ->rows(6)
                                            ->helperText(__('forms.helpers.default_instructions_included_in_rfq_documents_sent_to'))
                                            ->columnSpanFull(),
                                        Textarea::make('po_terms')
                                            ->label(__('forms.labels.default_po_terms_conditions'))
                                            ->rows(6)
                                            ->helperText(__('forms.helpers.default_terms_and_conditions_for_purchase_orders'))
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                        Tabs\Tab::make(__('forms.tabs.bank_details_for_documents'))
                            ->icon('heroicon-o-building-library')
                            ->schema([
                                Section::make(__('forms.sections.bank_information'))
                                    ->description(__('forms.descriptions.this_text_will_appear_on_invoices_and_other_financial'))
                                    ->schema([
                                        Textarea::make('bank_details_for_documents')
                                            ->label(__('forms.labels.bank_details'))
                                            ->rows(8)
                                            ->placeholder("Bank: HSBC Shanghai\nAccount: 1234567890\nSWIFT: HSBCCNSH\nIBAN: CN12345678901234")
                                            ->helperText(__('forms.helpers.enter_the_bank_details_exactly_as_they_should_appear_on'))
                                            ->columnSpanFull(),
                                    ]),
                            ]),

                    ])
                    ->columnSpanFull(),
            ]);
    }
}
