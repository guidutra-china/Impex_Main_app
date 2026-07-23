<?php

namespace App\Filament\Resources\Finance\DebitNotes\Schemas;

use App\Domain\Financial\Enums\PartyType;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\Settings\Models\Currency;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DebitNoteForm
{
    /**
     * The party_type select stores its state as a PartyType enum, so a raw
     * `=== PartyType::X->value` (string) comparison in a `$get()` closure is
     * always false. Normalize before comparing.
     */
    private static function partyIs($get, PartyType $party): bool
    {
        $value = $get('party_type');

        if (! $value instanceof PartyType) {
            $value = filled($value) ? PartyType::tryFrom((string) $value) : null;
        }

        return $value === $party;
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('forms.sections.debit_note_details'))
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Select::make('party_type')
                        ->label(__('forms.labels.party'))
                        ->options(PartyType::class)
                        ->default(PartyType::CLIENT->value)
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn ($set) => $set('company_id', null)),
                    Select::make('company_id')
                        ->label(fn ($get) => self::partyIs($get, PartyType::SUPPLIER)
                            ? __('forms.labels.supplier')
                            : __('forms.labels.client'))
                        ->relationship(
                            'company',
                            'name',
                            fn ($query, $get) => $query->whereHas(
                                'companyRoles',
                                fn ($q) => $q->where('role', self::partyIs($get, PartyType::SUPPLIER)
                                    ? PartyType::SUPPLIER->value
                                    : PartyType::CLIENT->value)
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
                            && self::partyIs($get, PartyType::CLIENT)),
                    Select::make('purchase_order_id')
                        ->label(__('forms.labels.purchase_order'))
                        ->options(fn ($get) => PurchaseOrder::query()
                            ->where('supplier_company_id', $get('company_id'))
                            ->pluck('reference', 'id'))
                        ->searchable()
                        ->nullable()
                        ->visible(fn ($get) => filled($get('company_id'))
                            && self::partyIs($get, PartyType::SUPPLIER)),
                    Select::make('shipment_id')
                        ->label(__('forms.labels.shipment'))
                        ->options(fn ($get) => Shipment::query()
                            ->when(
                                self::partyIs($get, PartyType::CLIENT),
                                fn ($q) => $q->where('company_id', $get('company_id'))
                            )
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
                            Hidden::make('id'),
                            TextInput::make('description')
                                ->label(__('forms.labels.description'))
                                ->required()
                                ->columnSpan(7),
                            TextInput::make('amount')
                                ->label(__('forms.labels.amount'))
                                ->numeric()
                                ->step('0.01')
                                ->required()
                                ->columnSpan(3),
                        ])
                        ->columns(10)
                        ->defaultItems(0)
                        ->addActionLabel('+ '.__('forms.labels.add_line')),
                ]),
        ]);
    }
}
