<?php

namespace App\Filament\Pages;

use App\Domain\Settings\DataTransferObjects\CompanySettings;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\SettingsPage;
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
                        Tabs\Tab::make('Company Information')
                            ->icon('heroicon-o-building-office')
                            ->schema([
                                Section::make('General')
                                    ->schema([
                                        TextInput::make('company_name')
                                            ->label('Company Name')
                                            ->required()
                                            ->maxLength(255),
                                        FileUpload::make('logo_path')
                                            ->label('Company Logo')
                                            ->image()
                                            ->directory('logos')
                                            ->disk('public')
                                            ->maxSize(2048)
                                            ->columnSpanFull(),
                                    ])
                                    ->columns(2),
                                Section::make('Address')
                                    ->schema([
                                        TextInput::make('address')
                                            ->label('Address')
                                            ->maxLength(500)
                                            ->columnSpanFull(),
                                        TextInput::make('city')
                                            ->label('City')
                                            ->maxLength(255),
                                        TextInput::make('state')
                                            ->label('State / Province')
                                            ->maxLength(255),
                                        TextInput::make('zip_code')
                                            ->label('Zip / Postal Code')
                                            ->maxLength(20),
                                        TextInput::make('country')
                                            ->label('Country')
                                            ->maxLength(255),
                                    ])
                                    ->columns(2),
                                Section::make('Contact')
                                    ->schema([
                                        TextInput::make('phone')
                                            ->label('Phone')
                                            ->tel()
                                            ->maxLength(50),
                                        TextInput::make('email')
                                            ->label('Email')
                                            ->email()
                                            ->maxLength(255),
                                        TextInput::make('website')
                                            ->label('Website')
                                            ->url()
                                            ->maxLength(255),
                                    ])
                                    ->columns(3),
                                Section::make('Legal')
                                    ->schema([
                                        TextInput::make('tax_id')
                                            ->label('Tax ID')
                                            ->maxLength(100),
                                        TextInput::make('registration_number')
                                            ->label('Registration Number')
                                            ->maxLength(100),
                                    ])
                                    ->columns(2),
                            ]),
                        Tabs\Tab::make('Document Settings')
                            ->icon('heroicon-o-document-text')
                            ->schema([
                                Section::make('Document Prefixes')
                                    ->description('Prefixes used for auto-generating document numbers.')
                                    ->schema([
                                        TextInput::make('invoice_prefix')
                                            ->label('Invoice Prefix')
                                            ->required()
                                            ->maxLength(10),
                                        TextInput::make('quote_prefix')
                                            ->label('Quote Prefix')
                                            ->required()
                                            ->maxLength(10),
                                        TextInput::make('po_prefix')
                                            ->label('Purchase Order Prefix')
                                            ->required()
                                            ->maxLength(10),
                                        TextInput::make('packing_list_prefix')
                                            ->label('Packing List Prefix')
                                            ->required()
                                            ->maxLength(10),
                                        TextInput::make('commercial_invoice_prefix')
                                            ->label('Commercial Invoice Prefix')
                                            ->required()
                                            ->maxLength(10),
                                    ])
                                    ->columns(3),
                                Section::make('Default Texts')
                                    ->schema([
                                        Textarea::make('footer_text')
                                            ->label('Document Footer Text')
                                            ->rows(3)
                                            ->columnSpanFull(),
                                        Textarea::make('rfq_default_instructions')
                                            ->label('Default RFQ Instructions')
                                            ->rows(6)
                                            ->helperText('Default instructions included in RFQ documents sent to suppliers.')
                                            ->columnSpanFull(),
                                        Textarea::make('po_terms')
                                            ->label('Default PO Terms & Conditions')
                                            ->rows(6)
                                            ->helperText('Default terms and conditions for purchase orders.')
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                        Tabs\Tab::make('Bank Details for Documents')
                            ->icon('heroicon-o-building-library')
                            ->schema([
                                Section::make('Bank Information')
                                    ->description('This text will appear on invoices and other financial documents. Format it as you want it to be printed.')
                                    ->schema([
                                        Textarea::make('bank_details_for_documents')
                                            ->label('Bank Details')
                                            ->rows(8)
                                            ->placeholder("Bank: HSBC Shanghai\nAccount: 1234567890\nSWIFT: HSBCCNSH\nIBAN: CN12345678901234")
                                            ->helperText('Enter the bank details exactly as they should appear on printed documents.')
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
