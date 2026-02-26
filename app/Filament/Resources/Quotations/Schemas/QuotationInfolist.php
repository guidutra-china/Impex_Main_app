<?php

namespace App\Filament\Resources\Quotations\Schemas;

use App\Domain\Infrastructure\Support\Money;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;

class QuotationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Quotation')
                    ->tabs([
                        Tabs\Tab::make(__('forms.tabs.general'))
                            ->icon('heroicon-o-information-circle')
                            ->schema(static::generalTab()),
                        Tabs\Tab::make(__('forms.tabs.commercial'))
                            ->icon('heroicon-o-currency-dollar')
                            ->schema(static::commercialTab()),
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
            Section::make(__('forms.sections.quotation_identity'))
                ->schema([
                    TextEntry::make('reference')
                        ->label(__('forms.labels.reference'))
                        ->weight(FontWeight::Bold)
                        ->copyable(),
                    TextEntry::make('status')
                        ->label(__('forms.labels.status'))
                        ->badge(),
                    TextEntry::make('version')
                        ->label(__('forms.labels.version'))
                        ->prefix('v')
                        ->badge()
                        ->color('info'),
                    TextEntry::make('creator.name')
                        ->label(__('forms.labels.created_by'))
                        ->placeholder('—'),
                ])
                ->columns(4),

            Section::make(__('forms.sections.client'))
                ->schema([
                    TextEntry::make('company.name')
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
        ];
    }

    protected static function commercialTab(): array
    {
        return [
            Section::make(__('forms.sections.pricing_commission'))
                ->schema([
                    TextEntry::make('currency_code')
                        ->label(__('forms.labels.currency'))
                        ->badge()
                        ->color('gray'),
                    TextEntry::make('commission_type')
                        ->label(__('forms.labels.commission_model'))
                        ->badge(),
                    TextEntry::make('commission_rate')
                        ->label(__('forms.labels.commission_rate'))
                        ->suffix('%')
                        ->placeholder('—'),
                    IconEntry::make('show_suppliers')
                        ->label(__('forms.labels.show_suppliers_to_client'))
                        ->boolean(),
                ])
                ->columns(4),

            Section::make(__('forms.sections.terms_validity'))
                ->schema([
                    TextEntry::make('paymentTerm.name')
                        ->label(__('forms.labels.payment_terms'))
                        ->placeholder('—'),
                    TextEntry::make('validity_days')
                        ->label(__('forms.labels.validity'))
                        ->suffix(' days')
                        ->placeholder('—'),
                    TextEntry::make('valid_until')
                        ->label(__('forms.labels.valid_until'))
                        ->date('d/m/Y')
                        ->placeholder('—')
                        ->color(fn ($record) => $record->valid_until && $record->valid_until->isPast() ? 'danger' : null),
                ])
                ->columns(3),
        ];
    }

    protected static function summaryTab(): array
    {
        return [
            Section::make(__('forms.sections.financial_summary'))
                ->schema([
                    TextEntry::make('subtotal')
                        ->label(__('forms.labels.subtotal'))
                        ->formatStateUsing(fn ($state) => Money::format($state))
                        ->prefix('$ ')
                        ->weight(FontWeight::Bold),
                    TextEntry::make('commission_amount')
                        ->label(__('forms.labels.commission_separate'))
                        ->formatStateUsing(fn ($state) => Money::format($state))
                        ->prefix('$ ')
                        ->visible(fn ($record) => $record->commission_amount > 0),
                    TextEntry::make('total')
                        ->label(__('forms.labels.total'))
                        ->formatStateUsing(fn ($state) => Money::format($state))
                        ->prefix('$ ')
                        ->weight(FontWeight::Bold)
                        ->color('success'),
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
                        ->label(__('forms.labels.client_notes'))
                        ->placeholder(__('forms.placeholders.no_client_notes'))
                        ->columnSpanFull()
                        ->markdown(),
                    TextEntry::make('internal_notes')
                        ->label(__('forms.labels.internal_notes'))
                        ->placeholder(__('forms.placeholders.no_internal_notes'))
                        ->columnSpanFull(),
                ]),
        ];
    }
}
