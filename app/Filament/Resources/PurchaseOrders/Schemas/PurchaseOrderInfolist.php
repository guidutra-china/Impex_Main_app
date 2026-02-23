<?php

namespace App\Filament\Resources\PurchaseOrders\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;

class PurchaseOrderInfolist
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
                        Tabs\Tab::make('Summary')
                            ->icon('heroicon-o-calculator')
                            ->schema(static::summaryTab()),
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
                    TextEntry::make('reference')
                        ->label('Reference')
                        ->weight(FontWeight::Bold)
                        ->copyable(),
                    TextEntry::make('status')
                        ->label('Status')
                        ->badge(),
                    TextEntry::make('proformaInvoice.reference')
                        ->label('Proforma Invoice')
                        ->weight(FontWeight::Bold)
                        ->url(fn ($record) => $record->proforma_invoice_id
                            ? route('filament.admin.resources.proforma-invoices.view', $record->proforma_invoice_id)
                            : null
                        )
                        ->color('primary'),
                    TextEntry::make('creator.name')
                        ->label('Created By')
                        ->placeholder('—'),
                ])
                ->columns(4),

            Section::make('Supplier')
                ->schema([
                    TextEntry::make('supplierCompany.name')
                        ->label('Company')
                        ->weight(FontWeight::Bold)
                        ->icon('heroicon-o-building-office-2'),
                    TextEntry::make('contact.name')
                        ->label('Contact')
                        ->icon('heroicon-o-user')
                        ->placeholder('—'),
                    TextEntry::make('contact.email')
                        ->label('Email')
                        ->icon('heroicon-o-envelope')
                        ->placeholder('—')
                        ->copyable(),
                    TextEntry::make('contact.phone')
                        ->label('Phone')
                        ->icon('heroicon-o-phone')
                        ->placeholder('—'),
                ])
                ->columns(2),

            Section::make('Dates')
                ->schema([
                    TextEntry::make('issue_date')
                        ->label('Issue Date')
                        ->date('d/m/Y'),
                    TextEntry::make('expected_delivery_date')
                        ->label('Expected Delivery')
                        ->date('d/m/Y')
                        ->placeholder('—'),
                ])
                ->columns(2),
        ];
    }

    protected static function commercialTab(): array
    {
        return [
            Section::make('Commercial Terms')
                ->schema([
                    TextEntry::make('currency_code')
                        ->label('Currency')
                        ->badge()
                        ->color('gray'),
                    TextEntry::make('incoterm')
                        ->label('Incoterm')
                        ->placeholder('—'),
                    TextEntry::make('paymentTerm.name')
                        ->label('Payment Terms')
                        ->placeholder('—'),
                ])
                ->columns(3),
        ];
    }

    protected static function confirmationTab(): array
    {
        return [
            Section::make('Supplier Confirmation')
                ->schema([
                    TextEntry::make('confirmation_method')
                        ->label('Method')
                        ->badge()
                        ->placeholder('Not confirmed yet'),
                    TextEntry::make('confirmation_reference')
                        ->label('Reference')
                        ->placeholder('—')
                        ->copyable(),
                    TextEntry::make('confirmed_at')
                        ->label('Confirmed At')
                        ->dateTime('d/m/Y H:i')
                        ->placeholder('—'),
                    TextEntry::make('confirmedByUser.name')
                        ->label('Confirmed By')
                        ->placeholder('—'),
                ])
                ->columns(2),
        ];
    }

    protected static function summaryTab(): array
    {
        return [
            Section::make('Financial Summary')
                ->schema([
                    TextEntry::make('total')
                        ->label('Total Cost')
                        ->formatStateUsing(fn ($state) => number_format($state / 100, 2))
                        ->prefix('$ ')
                        ->weight(FontWeight::Bold)
                        ->color('danger'),
                    TextEntry::make('items_count')
                        ->label('Total Items')
                        ->state(fn ($record) => $record->items->count()),
                ])
                ->columns(2),
        ];
    }

    protected static function notesTab(): array
    {
        return [
            Section::make('Notes')
                ->schema([
                    TextEntry::make('notes')
                        ->label('Supplier Notes')
                        ->placeholder('No supplier notes.')
                        ->columnSpanFull()
                        ->markdown(),
                    TextEntry::make('internal_notes')
                        ->label('Internal Notes')
                        ->placeholder('No internal notes.')
                        ->columnSpanFull(),
                    TextEntry::make('shipping_instructions')
                        ->label('Shipping Instructions')
                        ->placeholder('No shipping instructions.')
                        ->columnSpanFull(),
                ]),
        ];
    }
}
