<?php

namespace App\Filament\Resources\Quotations\Schemas;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Models\Contact;
use App\Domain\Quotations\Enums\CommissionType;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\PaymentTerm;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class QuotationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Quotation')
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
                        ->placeholder('QT-2026-XXXX'),
                    Select::make('status')
                        ->label('Status')
                        ->options(QuotationStatus::class)
                        ->required()
                        ->default(QuotationStatus::DRAFT),
                ])
                ->columns(2),

            Section::make('Client')
                ->schema([
                    Select::make('company_id')
                        ->label('Company')
                        ->options(
                            fn () => Company::query()
                                ->whereHas('companyRoles', fn ($q) => $q->where('role', \App\Domain\CRM\Enums\CompanyRole::CLIENT))
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
                        ->helperText('Select the client contact for this quotation.'),
                ])
                ->columns(2),
        ];
    }

    protected static function commercialTab(): array
    {
        return [
            Section::make('Pricing & Commission')
                ->schema([
                    Select::make('currency_code')
                        ->label('Currency')
                        ->options(fn () => Currency::where('is_active', true)->pluck('code', 'code'))
                        ->searchable()
                        ->required()
                        ->default('USD'),
                    Select::make('commission_type')
                        ->label('Commission Model')
                        ->options(CommissionType::class)
                        ->required()
                        ->default(CommissionType::EMBEDDED)
                        ->live()
                        ->helperText('Embedded: commission per item. Separate: commission on total.'),
                    TextInput::make('commission_rate')
                        ->label('Commission Rate (%)')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->step(0.01)
                        ->suffix('%')
                        ->default(0)
                        ->visible(fn (Get $get) => $get('commission_type') === CommissionType::SEPARATE->value || $get('commission_type') === CommissionType::SEPARATE)
                        ->helperText('Applied to the total value (Separate model only).'),
                    Toggle::make('show_suppliers')
                        ->label('Show Suppliers to Client')
                        ->default(false)
                        ->helperText('If enabled, supplier names will appear in the client PDF.'),
                ])
                ->columns(2),

            Section::make('Terms & Validity')
                ->schema([
                    Select::make('payment_term_id')
                        ->label('Payment Terms')
                        ->options(fn () => PaymentTerm::where('is_active', true)->pluck('name', 'id'))
                        ->searchable()
                        ->preload(),
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
