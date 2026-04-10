<?php

namespace App\Filament\Resources\Finance\DebitNotes\Schemas;

use App\Domain\Infrastructure\Support\Money;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\Settings\Models\Currency;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DebitNoteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('forms.sections.debit_note_details'))
                ->columns(2)
                ->schema([
                    Select::make('company_id')
                        ->label(__('forms.labels.client'))
                        ->relationship(
                            'company',
                            'name',
                            fn ($query) => $query->whereHas('companyRoles', fn ($q) => $q->where('role', 'client'))
                        )
                        ->required()
                        ->searchable()
                        ->preload()
                        ->live(),
                    Select::make('currency_code')
                        ->label(__('forms.labels.currency'))
                        ->options(fn () => Currency::pluck('code', 'code'))
                        ->required()
                        ->searchable(),
                    Select::make('proforma_invoice_id')
                        ->label(__('forms.labels.proforma_invoice'))
                        ->options(fn ($get) => ProformaInvoice::query()
                            ->where('company_id', $get('company_id'))
                            ->pluck('reference', 'id'))
                        ->searchable()
                        ->nullable()
                        ->visible(fn ($get) => filled($get('company_id'))),
                    Select::make('shipment_id')
                        ->label(__('forms.labels.shipment'))
                        ->options(fn ($get) => Shipment::query()
                            ->where('company_id', $get('company_id'))
                            ->pluck('reference', 'id'))
                        ->searchable()
                        ->nullable()
                        ->visible(fn ($get) => filled($get('company_id'))),
                    DatePicker::make('due_date')
                        ->label(__('forms.labels.due_date'))
                        ->nullable(),
                    Textarea::make('notes')
                        ->label(__('forms.labels.notes'))
                        ->rows(2)
                        ->columnSpanFull(),
                ]),

            Section::make(__('forms.sections.line_items'))
                ->columnSpanFull()
                ->schema([
                    Repeater::make('line_items')
                        ->label('')
                        ->schema([
                            TextInput::make('description')
                                ->label(__('forms.labels.description'))
                                ->required()
                                ->columnSpan(5),
                            TextInput::make('amount')
                                ->label(__('forms.labels.amount'))
                                ->numeric()
                                ->step('0.01')
                                ->required()
                                ->dehydrateStateUsing(fn ($state) => Money::toMinor((float) $state))
                                ->formatStateUsing(fn ($state) => $state ? Money::toMajor($state) : null)
                                ->columnSpan(3),
                            Select::make('currency_code')
                                ->label(__('forms.labels.currency'))
                                ->options(fn () => Currency::pluck('code', 'code'))
                                ->required()
                                ->columnSpan(2),
                        ])
                        ->columns(10)
                        ->defaultItems(0)
                        ->addActionLabel('+ ' . __('forms.labels.add_line')),
                ]),
        ]);
    }
}
