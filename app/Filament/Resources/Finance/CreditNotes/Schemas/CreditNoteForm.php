<?php

namespace App\Filament\Resources\Finance\CreditNotes\Schemas;

use App\Domain\Financial\Enums\CreditNoteParty;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\Settings\Models\Currency;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CreditNoteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('forms.sections.credit_note_details'))
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Select::make('party_type')
                        ->label(__('forms.labels.party'))
                        ->options(CreditNoteParty::class)
                        ->default(CreditNoteParty::SUPPLIER->value)
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn ($set) => $set('company_id', null)),
                    Select::make('company_id')
                        ->label(fn ($get) => $get('party_type') === CreditNoteParty::CLIENT->value
                            ? __('forms.labels.client')
                            : __('forms.labels.supplier'))
                        ->relationship(
                            'company',
                            'name',
                            fn ($query, $get) => $query->whereHas(
                                'companyRoles',
                                fn ($q) => $q->where('role', $get('party_type') ?: CreditNoteParty::SUPPLIER->value)
                            )
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
                        ->visible(fn ($get) => filled($get('company_id'))
                            && $get('party_type') === CreditNoteParty::CLIENT->value),
                    Select::make('purchase_order_id')
                        ->label(__('forms.labels.purchase_order'))
                        ->options(fn ($get) => PurchaseOrder::query()
                            ->where('supplier_company_id', $get('company_id'))
                            ->pluck('reference', 'id'))
                        ->searchable()
                        ->nullable()
                        ->visible(fn ($get) => filled($get('company_id'))
                            && $get('party_type') === CreditNoteParty::SUPPLIER->value),
                    Select::make('shipment_id')
                        ->label(__('forms.labels.shipment'))
                        ->options(fn ($get) => Shipment::query()
                            ->when(
                                $get('party_type') === CreditNoteParty::CLIENT->value,
                                fn ($q) => $q->where('company_id', $get('company_id'))
                            )
                            ->pluck('reference', 'id'))
                        ->searchable()
                        ->nullable()
                        ->visible(fn ($get) => filled($get('company_id'))),
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
                                ->columnSpan(3),
                            Select::make('currency_code')
                                ->label(__('forms.labels.currency'))
                                ->options(fn () => Currency::pluck('code', 'code'))
                                ->required()
                                ->columnSpan(2),
                        ])
                        ->columns(10)
                        ->defaultItems(0)
                        ->addActionLabel('+ '.__('forms.labels.add_line')),
                ]),
        ]);
    }
}
