<?php

namespace App\Filament\Resources\SupplierQuotations\Schemas;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Models\Contact;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Quotations\Enums\Incoterm;
use App\Domain\Settings\Enums\CalculationBase;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\PaymentTerm;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput as FormTextInput;
use Filament\Forms\Components\Toggle;
use App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Tabs;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class SupplierQuotationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('SupplierQuotation')
                    ->tabs([
                        Tabs\Tab::make('General')
                            ->icon('heroicon-o-information-circle')
                            ->schema(static::generalTab()),
                        Tabs\Tab::make('Commercial')
                            ->icon('heroicon-o-currency-dollar')
                            ->schema(static::commercialTab()),
                        Tabs\Tab::make('Notes')
                            ->icon('heroicon-o-chat-bubble-left-right')
                            ->schema(static::notesTab()),
                    ])
                    ->columnSpanFull()
                    ->persistTabInQueryString(),
            ]);
    }

    protected static function generalTab(): array
    {
        return [
            Section::make('Quotation Identity')
                ->schema([
                    TextInput::make('reference')
                        ->label('Reference')
                        ->disabled()
                        ->dehydrated()
                        ->helperText('Auto-generated on creation.')
                        ->placeholder('SQ-2026-XXXXX'),
                    Select::make('status')
                        ->label('Status')
                        ->options(SupplierQuotationStatus::class)
                        ->required()
                        ->default(SupplierQuotationStatus::REQUESTED),
                    Select::make('inquiry_id')
                        ->label('Inquiry')
                        ->options(
                            fn () => Inquiry::query()
                                ->orderByDesc('id')
                                ->limit(100)
                                ->get()
                                ->mapWithKeys(fn ($i) => [
                                    $i->id => $i->reference . ' — ' . ($i->company?->name ?? 'N/A'),
                                ])
                        )
                        ->searchable()
                        ->required()
                        ->helperText('The client inquiry this supplier quotation is for.'),
                    Select::make('currency_code')
                        ->label('Currency')
                        ->options(fn () => Currency::where('is_active', true)->pluck('code', 'code'))
                        ->searchable()
                        ->required()
                        ->default('USD'),
                ])
                ->columns(2),

            Section::make('Supplier')
                ->schema([
                    Select::make('company_id')
                        ->label('Supplier')
                        ->options(
                            fn () => Company::query()
                                ->whereHas('companyRoles', fn ($q) => $q->where('role', CompanyRole::SUPPLIER))
                                ->orderBy('name')
                                ->pluck('name', 'id')
                        )
                        ->searchable()
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (Set $set) => $set('contact_id', null)),
                    Select::make('contact_id')
                        ->label('Contact')
                        ->options(function (Get $get) {
                            $companyId = $get('company_id');
                            if (! $companyId) {
                                return [];
                            }

                            return Contact::where('company_id', $companyId)
                                ->orderBy('name')
                                ->pluck('name', 'id');
                        })
                        ->searchable()
                        ->helperText('Supplier contact person.'),
                    TextInput::make('supplier_reference')
                        ->label('Supplier Quotation Number')
                        ->maxLength(100)
                        ->helperText('The supplier\'s own reference/quotation number.')
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Section::make('Dates')
                ->schema([
                    DatePicker::make('requested_at')
                        ->label('Requested Date')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->default(now())
                        ->required(),
                    DatePicker::make('received_at')
                        ->label('Received Date')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->helperText('Date the supplier sent the quotation.'),
                    DatePicker::make('valid_until')
                        ->label('Valid Until')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->helperText('Supplier quotation expiration date.'),
                ])
                ->columns(3),
        ];
    }

    protected static function commercialTab(): array
    {
        return [
            Section::make('Commercial Terms')
                ->schema([
                    TextInput::make('lead_time_days')
                        ->label('Lead Time (days)')
                        ->numeric()
                        ->minValue(0)
                        ->helperText('Overall lead time in days from order to delivery.'),
                    TextInput::make('moq')
                        ->label('MOQ')
                        ->numeric()
                        ->minValue(0)
                        ->helperText('Minimum order quantity for this quotation.'),
                    Select::make('incoterm')
                        ->label('Incoterm')
                        ->options(Incoterm::class)
                        ->searchable(),
                    Select::make('payment_term_id')
                        ->label('Payment Terms')
                        ->relationship('paymentTerm', 'name')
                        ->options(fn () => PaymentTerm::active()->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->preload()
                        ->createOptionForm([
                            TextInput::make('name')
                                ->label('Name')
                                ->required()
                                ->maxLength(255)
                                ->placeholder('e.g., 30/70 - 30% deposit, 70% before shipment')
                                ->columnSpanFull(),
                            Textarea::make('description')
                                ->label('Description')
                                ->rows(2)
                                ->maxLength(65535)
                                ->columnSpanFull(),
                            Toggle::make('is_active')
                                ->label('Active')
                                ->default(true),
                            Repeater::make('stages')
                                ->relationship()
                                ->schema([
                                    FormTextInput::make('percentage')
                                        ->label('Percentage')
                                        ->required()
                                        ->numeric()
                                        ->suffix('%')
                                        ->minValue(1)
                                        ->maxValue(100),
                                    FormTextInput::make('days')
                                        ->label('Days')
                                        ->required()
                                        ->numeric()
                                        ->default(0)
                                        ->minValue(0),
                                    Select::make('calculation_base')
                                        ->label('Calculation Base')
                                        ->options(CalculationBase::class)
                                        ->required()
                                        ->default(CalculationBase::ORDER_DATE),
                                ])
                                ->columns(3)
                                ->orderColumn('sort_order')
                                ->reorderable()
                                ->addActionLabel('Add Stage')
                                ->defaultItems(1)
                                ->minItems(1)
                                ->columnSpanFull(),
                        ])
                        ->createOptionModalHeading('Create Payment Term')
                        ->helperText('Select or create a new payment term.')
                        ->columnSpanFull(),
                ])
                ->columns(3),
        ];
    }

    protected static function notesTab(): array
    {
        return [
            Section::make('Notes')
                ->schema([
                    Textarea::make('notes')
                        ->label('Supplier Notes')
                        ->rows(5)
                        ->maxLength(5000)
                        ->helperText('Notes from the supplier, conditions, remarks.')
                        ->columnSpanFull(),
                    Textarea::make('internal_notes')
                        ->label('Internal Notes')
                        ->rows(4)
                        ->maxLength(5000)
                        ->helperText('Internal analysis, observations, comparison notes.')
                        ->columnSpanFull(),
                ]),
        ];
    }
}
