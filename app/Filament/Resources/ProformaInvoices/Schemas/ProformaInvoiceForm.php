<?php

namespace App\Filament\Resources\ProformaInvoices\Schemas;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Models\Contact;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\ProformaInvoices\Enums\ConfirmationMethod;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
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

class ProformaInvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('ProformaInvoice')
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
            Section::make('Invoice Identity')
                ->schema([
                    TextInput::make('reference')
                        ->label('Reference')
                        ->disabled()
                        ->dehydrated()
                        ->helperText('Auto-generated on creation.')
                        ->placeholder('PI-2026-XXXXX'),
                    Select::make('status')
                        ->label('Status')
                        ->options(ProformaInvoiceStatus::class)
                        ->required()
                        ->default(ProformaInvoiceStatus::DRAFT->value),
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
                        ->live()
                        ->afterStateUpdated(function (Set $set, ?string $state) {
                            if ($state) {
                                $inquiry = Inquiry::find($state);
                                if ($inquiry) {
                                    $set('company_id', $inquiry->company_id);
                                    $set('contact_id', $inquiry->contact_id);
                                    $set('currency_code', $inquiry->currency_code ?? 'USD');
                                }
                            }
                        })
                        ->helperText('The client inquiry this proforma invoice is for.'),
                    Select::make('currency_code')
                        ->label('Currency')
                        ->options(fn () => Currency::where('is_active', true)->pluck('code', 'code'))
                        ->searchable()
                        ->required()
                        ->default('USD'),
                ])
                ->columns(2),

            Section::make('Client')
                ->schema([
                    Select::make('company_id')
                        ->label('Client')
                        ->options(
                            fn () => Company::query()
                                ->whereHas('companyRoles', fn ($q) => $q->where('role', CompanyRole::CLIENT))
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
                        ->helperText('Client contact person.'),
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
                    TextInput::make('validity_days')
                        ->label('Validity (days)')
                        ->numeric()
                        ->minValue(1)
                        ->default(30)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Set $set, ?string $state) {
                            if ($state && is_numeric($state)) {
                                $set('valid_until', now()->addDays((int) $state)->format('Y-m-d'));
                            }
                        }),
                    DatePicker::make('valid_until')
                        ->label('Valid Until')
                        ->native(false)
                        ->displayFormat('d/m/Y'),
                ])
                ->columns(3),
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
            Section::make('Client Confirmation')
                ->schema([
                    Select::make('confirmation_method')
                        ->label('Confirmation Method')
                        ->options(ConfirmationMethod::class)
                        ->helperText('How the client confirmed this proforma invoice.'),
                    TextInput::make('confirmation_reference')
                        ->label('Confirmation Reference')
                        ->maxLength(255)
                        ->helperText('Email subject, message ID, document number, etc.'),
                    DateTimePicker::make('confirmed_at')
                        ->label('Confirmed At')
                        ->native(false)
                        ->displayFormat('d/m/Y H:i')
                        ->helperText('Date and time the client confirmed.'),
                ])
                ->columns(3)
                ->description('Record how and when the client confirmed this proforma invoice.'),
        ];
    }

    protected static function notesTab(): array
    {
        return [
            Section::make('Notes')
                ->schema([
                    Textarea::make('notes')
                        ->label('Client Notes')
                        ->rows(4)
                        ->maxLength(5000)
                        ->helperText('Visible to the client in the PDF.')
                        ->columnSpanFull(),
                    Textarea::make('internal_notes')
                        ->label('Internal Notes')
                        ->rows(4)
                        ->maxLength(5000)
                        ->helperText('Only visible internally, never shown to client.')
                        ->columnSpanFull(),
                ]),
        ];
    }
}
