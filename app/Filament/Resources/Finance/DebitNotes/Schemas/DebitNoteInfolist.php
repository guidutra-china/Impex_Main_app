<?php

namespace App\Filament\Resources\Finance\DebitNotes\Schemas;

use App\Domain\Infrastructure\Support\Money;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DebitNoteInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('forms.sections.debit_note_details'))
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('reference')
                        ->label(__('forms.labels.reference')),
                    TextEntry::make('company.name')
                        ->label(__('forms.labels.client')),
                    TextEntry::make('status')
                        ->label(__('forms.labels.status'))
                        ->badge(),
                    TextEntry::make('proformaInvoice.reference')
                        ->label(__('forms.labels.proforma_invoice'))
                        ->placeholder('—'),
                    TextEntry::make('shipment.reference')
                        ->label(__('forms.labels.shipment'))
                        ->placeholder('—'),
                    TextEntry::make('total_amount')
                        ->label(__('forms.labels.total_amount'))
                        ->formatStateUsing(fn ($state) => Money::format($state)),
                    TextEntry::make('currency_code')
                        ->label(__('forms.labels.currency')),
                    TextEntry::make('paid_amount')
                        ->label(__('forms.labels.paid_amount'))
                        ->state(fn ($record) => $record->paid_amount)
                        ->formatStateUsing(fn ($state) => Money::format((int) $state))
                        ->badge()
                        ->color(fn ($record) => $record->paid_amount >= (int) $record->total_amount && (int) $record->total_amount > 0 ? 'success' : 'gray'),
                    TextEntry::make('remaining_amount')
                        ->label(__('forms.labels.remaining_amount'))
                        ->state(fn ($record) => $record->remaining_amount)
                        ->formatStateUsing(fn ($state) => Money::format((int) $state))
                        ->badge()
                        ->color(fn ($state) => ((int) $state) > 0 ? 'warning' : 'success'),
                    TextEntry::make('issued_at')
                        ->label(__('forms.labels.issued_at'))
                        ->dateTime('d/m/Y H:i')
                        ->placeholder('—'),
                    TextEntry::make('due_date')
                        ->label(__('forms.labels.due_date'))
                        ->date('d/m/Y')
                        ->placeholder('—'),
                    TextEntry::make('creator.name')
                        ->label(__('forms.labels.created_by'))
                        ->placeholder('—'),
                    TextEntry::make('notes')
                        ->label(__('forms.labels.notes'))
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),

            Section::make(__('forms.sections.line_items'))
                ->columnSpanFull()
                ->schema([
                    RepeatableEntry::make('lineItems')
                        ->label('')
                        ->schema([
                            TextEntry::make('description')
                                ->label(__('forms.labels.description')),
                            TextEntry::make('document_reference')
                                ->label(__('forms.labels.document'))
                                ->getStateUsing(function ($record) {
                                    $ref = $record->proformaInvoice?->reference
                                        ?? $record->shipment?->reference
                                        ?? '—';
                                    $clientRef = $record->proformaInvoice?->client_reference
                                        ?? $record->shipment?->client_reference
                                        ?? null;

                                    return $clientRef ? "{$ref} (Ref: {$clientRef})" : $ref;
                                }),
                            TextEntry::make('amount')
                                ->label(__('forms.labels.amount'))
                                ->formatStateUsing(fn ($state) => Money::format($state)),
                            TextEntry::make('currency_code')
                                ->label(__('forms.labels.currency')),
                        ])
                        ->columns(4),
                ]),

            Section::make(__('forms.sections.payments_received'))
                ->columnSpanFull()
                ->visible(fn ($record) => $record && $record->payments->isNotEmpty())
                ->schema([
                    RepeatableEntry::make('payments')
                        ->label('')
                        ->schema([
                            TextEntry::make('reference')
                                ->label(__('forms.labels.reference')),
                            TextEntry::make('payment_date')
                                ->label(__('forms.labels.payment_date'))
                                ->date('d/m/Y'),
                            TextEntry::make('paymentMethod.name')
                                ->label(__('forms.labels.payment_method'))
                                ->placeholder('—'),
                            TextEntry::make('amount')
                                ->label(__('forms.labels.amount'))
                                ->formatStateUsing(fn ($state) => Money::format((int) $state)),
                            TextEntry::make('currency_code')
                                ->label(__('forms.labels.currency')),
                            TextEntry::make('status')
                                ->label(__('forms.labels.status'))
                                ->badge(),
                        ])
                        ->columns(6),
                ]),
        ]);
    }
}
