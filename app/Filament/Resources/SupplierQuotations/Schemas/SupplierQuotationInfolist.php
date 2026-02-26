<?php

namespace App\Filament\Resources\SupplierQuotations\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;

class SupplierQuotationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('SupplierQuotation')
                    ->tabs([
                        Tabs\Tab::make(__('forms.tabs.general'))
                            ->icon('heroicon-o-information-circle')
                            ->schema(static::generalTab()),
                        Tabs\Tab::make(__('forms.tabs.commercial'))
                            ->icon('heroicon-o-currency-dollar')
                            ->schema(static::commercialTab()),
                        Tabs\Tab::make(__('forms.tabs.notes'))
                            ->icon('heroicon-o-chat-bubble-left-right')
                            ->schema(static::notesTab()),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    protected static function generalTab(): array
    {
        return [
            Section::make(__('forms.sections.quotation_identity'))
                ->schema([
                    TextEntry::make('reference')
                        ->label(__('forms.labels.reference'))
                        ->copyable(),
                    TextEntry::make('status')
                        ->label(__('forms.labels.status'))
                        ->badge(),
                    TextEntry::make('inquiry.reference')
                        ->label(__('forms.labels.inquiry')),
                    TextEntry::make('currency_code')
                        ->label(__('forms.labels.currency')),
                ])
                ->columns(2),

            Section::make(__('forms.sections.supplier'))
                ->schema([
                    TextEntry::make('company.name')
                        ->label(__('forms.labels.supplier')),
                    TextEntry::make('contact.name')
                        ->label(__('forms.labels.contact'))
                        ->placeholder('—'),
                    TextEntry::make('supplier_reference')
                        ->label(__('forms.labels.supplier_quotation_number'))
                        ->placeholder('—'),
                ])
                ->columns(2),

            Section::make(__('forms.sections.dates'))
                ->schema([
                    TextEntry::make('requested_at')
                        ->label(__('forms.labels.requested_date'))
                        ->date('d/m/Y'),
                    TextEntry::make('received_at')
                        ->label(__('forms.labels.received_date'))
                        ->date('d/m/Y')
                        ->placeholder(__('forms.placeholders.pending')),
                    TextEntry::make('valid_until')
                        ->label(__('forms.labels.valid_until'))
                        ->date('d/m/Y')
                        ->placeholder('—'),
                    TextEntry::make('creator.name')
                        ->label(__('forms.labels.created_by'))
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
                    TextEntry::make('lead_time_days')
                        ->label(__('forms.labels.lead_time'))
                        ->suffix(' days')
                        ->placeholder('—'),
                    TextEntry::make('moq')
                        ->label(__('forms.labels.moq'))
                        ->placeholder('—'),
                    TextEntry::make('incoterm')
                        ->label(__('forms.labels.incoterm'))
                        ->placeholder('—'),
                    TextEntry::make('paymentTerm.name')
                        ->label(__('forms.labels.payment_terms'))
                        ->placeholder('—'),
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
                        ->placeholder('—')
                        ->columnSpanFull(),
                    TextEntry::make('internal_notes')
                        ->label(__('forms.labels.internal_notes'))
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),
        ];
    }
}
