<?php

namespace App\Filament\Resources\PurchaseOrders\Schemas;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Models\Contact;
use App\Domain\ProformaInvoices\Enums\ConfirmationMethod;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Enums\PurchaseOrderStatus;
use App\Domain\Quotations\Enums\Incoterm;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\PaymentTerm;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class PurchaseOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('PurchaseOrder')
                    ->tabs([
                        Tabs\Tab::make('General')
                            ->icon('heroicon-o-information-circle')
                            ->schema(static::generalTab()),
                        Tabs\Tab::make('Commercial')
                            ->icon('heroicon-o-currency-dollar')
                            ->schema(static::commercialTab()),
                        Tabs\Tab::make('Confirmation')
                            ->icon('heroicon-o-check-badge')
                            ->schema(static::confirmationTab()),
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
            Section::make('Order Identity')
                ->schema([
                    TextInput::make('reference')
                        ->label('Reference')
                        ->disabled()
                        ->dehydrated()
                        ->helperText('Auto-generated on creation.')
                        ->placeholder('PO-2026-XXXXX'),
                    Select::make('status')
                        ->label('Status')
                        ->options(PurchaseOrderStatus::class)
                        ->required()
                        ->default(PurchaseOrderStatus::DRAFT->value),
                    Select::make('proforma_invoice_id')
                        ->label('Proforma Invoice')
                        ->options(
                            fn () => ProformaInvoice::query()
                                ->orderByDesc('id')
                                ->limit(100)
                                ->get()
                                ->mapWithKeys(fn ($pi) => [
                                    $pi->id => $pi->reference . ' — ' . ($pi->company?->name ?? 'N/A'),
                                ])
                        )
                        ->searchable()
                        ->required()
                        ->helperText('The proforma invoice this PO originates from.'),
                    Select::make('currency_code')
                        ->label('Currency')
                        ->options(fn () => Currency::where('is_active', true)->pluck('code', 'code'))
                        ->searchable()
                        ->required()
                        ->default('USD')
                        ->helperText('Can differ from PI currency for supplier negotiation.'),
                ])
                ->columns(2),

            Section::make('Supplier')
                ->schema([
                    Select::make('supplier_company_id')
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
                            $companyId = $get('supplier_company_id');
                            if (! $companyId) {
                                return [];
                            }

                            return Contact::where('company_id', $companyId)
                                ->orderBy('name')
                                ->pluck('name', 'id');
                        })
                        ->searchable()
                        ->helperText('Supplier contact person.'),
                ])
                ->columns(2),

            Section::make('Dates')
                ->schema([
                    DatePicker::make('issue_date')
                        ->label('Issue Date')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->default(now())
                        ->required(),
                    DatePicker::make('expected_delivery_date')
                        ->label('Expected Delivery')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->helperText('When the supplier is expected to deliver.'),
                ])
                ->columns(2),
        ];
    }

    protected static function commercialTab(): array
    {
        return [
            Section::make('Commercial Terms')
                ->schema([
                    Select::make('incoterm')
                        ->label('Incoterm')
                        ->options(Incoterm::class)
                        ->searchable(),
                    Select::make('payment_term_id')
                        ->label('Payment Terms')
                        ->options(fn () => PaymentTerm::where('is_active', true)->pluck('name', 'id'))
                        ->searchable()
                        ->preload(),
                ])
                ->columns(2),
        ];
    }

    protected static function confirmationTab(): array
    {
        return [
            Section::make('Supplier Confirmation')
                ->schema([
                    Select::make('confirmation_method')
                        ->label('Confirmation Method')
                        ->options(ConfirmationMethod::class)
                        ->helperText('How the supplier confirmed this purchase order.'),
                    TextInput::make('confirmation_reference')
                        ->label('Confirmation Reference')
                        ->maxLength(255)
                        ->helperText('Email subject, message ID, document number, etc.'),
                    DateTimePicker::make('confirmed_at')
                        ->label('Confirmed At')
                        ->native(false)
                        ->displayFormat('d/m/Y H:i')
                        ->helperText('Date and time the supplier confirmed.'),
                ])
                ->columns(3)
                ->description('Record how and when the supplier confirmed this purchase order.'),

            Section::make('Supplier Invoice')
                ->schema([
                    TextInput::make('supplier_invoice_number')
                        ->label('Invoice Number')
                        ->maxLength(255)
                        ->helperText('The supplier\'s own invoice/reference number.'),
                    DatePicker::make('supplier_invoice_date')
                        ->label('Invoice Date')
                        ->native(false)
                        ->displayFormat('d/m/Y'),
                ])
                ->columns(2)
                ->description('Invoice details. Upload invoice files, packing lists, and other documents in the Documents tab.'),
        ];
    }

    protected static function notesTab(): array
    {
        return [
            Section::make('Notes')
                ->schema([
                    Textarea::make('notes')
                        ->label('Supplier Notes')
                        ->rows(4)
                        ->maxLength(5000)
                        ->helperText('Visible to the supplier in the PDF.')
                        ->columnSpanFull(),
                    Textarea::make('internal_notes')
                        ->label('Internal Notes')
                        ->rows(4)
                        ->maxLength(5000)
                        ->helperText('Only visible internally, never shown to supplier.')
                        ->columnSpanFull(),
                    Textarea::make('shipping_instructions')
                        ->label('Shipping Instructions')
                        ->rows(4)
                        ->maxLength(5000)
                        ->helperText('Shipping/delivery instructions for the supplier.')
                        ->columnSpanFull(),
                ]),
        ];
    }
}
