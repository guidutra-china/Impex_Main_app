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
                        Tabs\Tab::make(__('forms.tabs.general'))
                            ->icon('heroicon-o-information-circle')
                            ->schema(static::generalTab()),
                        Tabs\Tab::make(__('forms.tabs.commercial'))
                            ->icon('heroicon-o-currency-dollar')
                            ->schema(static::commercialTab()),
                        Tabs\Tab::make(__('forms.tabs.confirmation'))
                            ->icon('heroicon-o-check-badge')
                            ->schema(static::confirmationTab()),
                        Tabs\Tab::make(__('forms.tabs.summary'))
                            ->icon('heroicon-o-calculator')
                            ->schema(static::summaryTab()),
                        Tabs\Tab::make(__('forms.tabs.notes'))
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
            Section::make(__('forms.sections.order_identity'))
                ->schema([
                    TextEntry::make('reference')
                        ->label(__('forms.labels.reference'))
                        ->weight(FontWeight::Bold)
                        ->copyable(),
                    TextEntry::make('status')
                        ->label(__('forms.labels.status'))
                        ->badge(),
                    TextEntry::make('proformaInvoice.reference')
                        ->label(__('forms.labels.proforma_invoice'))
                        ->weight(FontWeight::Bold)
                        ->url(fn ($record) => $record->proforma_invoice_id
                            ? route('filament.admin.resources.proforma-invoices.view', $record->proforma_invoice_id)
                            : null
                        )
                        ->color('primary'),
                    TextEntry::make('creator.name')
                        ->label(__('forms.labels.created_by'))
                        ->placeholder('—'),
                ])
                ->columns(4),

            Section::make(__('forms.sections.supplier'))
                ->schema([
                    TextEntry::make('supplierCompany.name')
                        ->label(__('forms.labels.company'))
                        ->weight(FontWeight::Bold)
                        ->icon('heroicon-o-building-office-2'),
                    TextEntry::make('contact.name')
                        ->label(__('forms.labels.contact'))
                        ->icon('heroicon-o-user')
                        ->placeholder('—'),
                    TextEntry::make('contact.email')
                        ->label(__('forms.labels.email'))
                        ->icon('heroicon-o-envelope')
                        ->placeholder('—')
                        ->copyable(),
                    TextEntry::make('contact.phone')
                        ->label(__('forms.labels.phone'))
                        ->icon('heroicon-o-phone')
                        ->placeholder('—'),
                ])
                ->columns(2),

            Section::make(__('forms.sections.dates'))
                ->schema([
                    TextEntry::make('issue_date')
                        ->label(__('forms.labels.issue_date'))
                        ->date('d/m/Y'),
                    TextEntry::make('expected_delivery_date')
                        ->label(__('forms.labels.expected_delivery'))
                        ->date('d/m/Y')
                        ->placeholder('—'),
                ])
                ->columns(2),
        ];
    }

    protected static function commercialTab(): array
    {
        return [
            Section::make(__('forms.sections.commercial_terms'))
                ->schema([
                    TextEntry::make('currency_code')
                        ->label(__('forms.labels.currency'))
                        ->badge()
                        ->color('gray'),
                    TextEntry::make('incoterm')
                        ->label(__('forms.labels.incoterm'))
                        ->placeholder('—'),
                    TextEntry::make('paymentTerm.name')
                        ->label(__('forms.labels.payment_terms'))
                        ->placeholder('—'),
                ])
                ->columns(3),
        ];
    }

    protected static function confirmationTab(): array
    {
        return [
            Section::make(__('forms.sections.supplier_confirmation'))
                ->schema([
                    TextEntry::make('confirmation_method')
                        ->label(__('forms.labels.method'))
                        ->badge()
                        ->placeholder(__('forms.placeholders.not_confirmed_yet')),
                    TextEntry::make('confirmation_reference')
                        ->label(__('forms.labels.reference'))
                        ->placeholder('—')
                        ->copyable(),
                    TextEntry::make('confirmed_at')
                        ->label(__('forms.labels.confirmed_at'))
                        ->dateTime('d/m/Y H:i')
                        ->placeholder('—'),
                    TextEntry::make('confirmedByUser.name')
                        ->label(__('forms.labels.confirmed_by'))
                        ->placeholder('—'),
                ])
                ->columns(2),

            Section::make(__('forms.sections.supplier_invoice'))
                ->schema([
                    TextEntry::make('supplier_invoice_number')
                        ->label(__('forms.labels.invoice_number'))
                        ->weight(FontWeight::Bold)
                        ->copyable()
                        ->placeholder('—'),
                    TextEntry::make('supplier_invoice_date')
                        ->label(__('forms.labels.invoice_date'))
                        ->date('d/m/Y')
                        ->placeholder('—'),
                ])
                ->columns(2)
                ->description(__('forms.descriptions.invoice_details_files_are_available_in_the_documents_tab')),
        ];
    }

    protected static function summaryTab(): array
    {
        return [
            Section::make(__('forms.sections.financial_summary'))
                ->schema([
                    TextEntry::make('total')
                        ->label(__('forms.labels.total_cost'))
                        ->formatStateUsing(fn ($state) => \App\Domain\Infrastructure\Support\Money::format($state))
                        ->prefix('$ ')
                        ->weight(FontWeight::Bold)
                        ->color('danger'),
                    TextEntry::make('items_count')
                        ->label(__('forms.labels.total_items'))
                        ->state(fn ($record) => $record->items->count()),
                ])
                ->columns(2),
        ];
    }

    protected static function notesTab(): array
    {
        return [
            Section::make(__('forms.sections.notes'))
                ->schema([
                    TextEntry::make('notes')
                        ->label(__('forms.labels.supplier_notes'))
                        ->placeholder(__('forms.placeholders.no_supplier_notes'))
                        ->columnSpanFull()
                        ->markdown(),
                    TextEntry::make('internal_notes')
                        ->label(__('forms.labels.internal_notes'))
                        ->placeholder(__('forms.placeholders.no_internal_notes'))
                        ->columnSpanFull(),
                    TextEntry::make('shipping_instructions')
                        ->label(__('forms.labels.shipping_instructions'))
                        ->placeholder(__('forms.placeholders.no_shipping_instructions'))
                        ->columnSpanFull(),
                ]),
        ];
    }
}
