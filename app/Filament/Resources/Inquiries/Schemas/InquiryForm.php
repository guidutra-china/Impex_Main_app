<?php

namespace App\Filament\Resources\Inquiries\Schemas;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Models\Contact;
use App\Domain\Inquiries\Enums\InquirySource;
use App\Domain\Inquiries\Enums\InquiryStatus;
use App\Domain\Settings\Models\Currency;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class InquiryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Inquiry')
                    ->tabs([
                        Tabs\Tab::make('General')
                            ->icon('heroicon-o-information-circle')
                            ->schema(static::generalTab()),
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
            Section::make('Inquiry Identity')
                ->schema([
                    TextInput::make('reference')
                        ->label('Reference')
                        ->disabled()
                        ->dehydrated()
                        ->helperText('Auto-generated on creation.')
                        ->placeholder('INQ-2026-XXXXX'),
                    Select::make('status')
                        ->label('Status')
                        ->options(InquiryStatus::class)
                        ->required()
                        ->default(InquiryStatus::RECEIVED->value),
                    Select::make('source')
                        ->label('Source')
                        ->options(InquirySource::class)
                        ->required()
                        ->default(InquirySource::EMAIL->value),
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
                        ->helperText('Select the client contact for this inquiry.'),
                ])
                ->columns(2),

            Section::make('Dates')
                ->schema([
                    DatePicker::make('received_at')
                        ->label('Received Date')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->default(now())
                        ->required(),
                    DatePicker::make('deadline')
                        ->label('Response Deadline')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->helperText('Client deadline for receiving the quotation.'),
                ])
                ->columns(2),
        ];
    }

    protected static function notesTab(): array
    {
        return [
            Section::make('Notes')
                ->schema([
                    Textarea::make('notes')
                        ->label('Client Notes / Requirements')
                        ->rows(5)
                        ->maxLength(5000)
                        ->helperText('Original client request details, requirements, or special conditions.')
                        ->columnSpanFull(),
                    Textarea::make('internal_notes')
                        ->label('Internal Notes')
                        ->rows(4)
                        ->maxLength(5000)
                        ->helperText('Internal observations, strategy notes, etc.')
                        ->columnSpanFull(),
                ]),
        ];
    }
}
