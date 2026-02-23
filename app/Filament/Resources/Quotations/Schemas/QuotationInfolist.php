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
                        Tabs\Tab::make('General')
                            ->icon('heroicon-o-information-circle')
                            ->schema(static::generalTab()),
                        Tabs\Tab::make('Commercial')
                            ->icon('heroicon-o-currency-dollar')
                            ->schema(static::commercialTab()),
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
            Section::make('Quotation Identity')
                ->schema([
                    TextEntry::make('reference')
                        ->label('Reference')
                        ->weight(FontWeight::Bold)
                        ->copyable(),
                    TextEntry::make('status')
                        ->label('Status')
                        ->badge(),
                    TextEntry::make('version')
                        ->label('Version')
                        ->prefix('v')
                        ->badge()
                        ->color('info'),
                    TextEntry::make('creator.name')
                        ->label('Created By')
                        ->placeholder('—'),
                ])
                ->columns(4),

            Section::make('Client')
                ->schema([
                    TextEntry::make('company.name')
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
        ];
    }

    protected static function commercialTab(): array
    {
        return [
            Section::make('Pricing & Commission')
                ->schema([
                    TextEntry::make('currency_code')
                        ->label('Currency')
                        ->badge()
                        ->color('gray'),
                    TextEntry::make('commission_type')
                        ->label('Commission Model')
                        ->badge(),
                    TextEntry::make('commission_rate')
                        ->label('Commission Rate')
                        ->suffix('%')
                        ->placeholder('—'),
                    IconEntry::make('show_suppliers')
                        ->label('Show Suppliers to Client')
                        ->boolean(),
                ])
                ->columns(4),

            Section::make('Terms & Validity')
                ->schema([
                    TextEntry::make('paymentTerm.name')
                        ->label('Payment Terms')
                        ->placeholder('—'),
                    TextEntry::make('validity_days')
                        ->label('Validity')
                        ->suffix(' days')
                        ->placeholder('—'),
                    TextEntry::make('valid_until')
                        ->label('Valid Until')
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
            Section::make('Financial Summary')
                ->schema([
                    TextEntry::make('subtotal')
                        ->label('Subtotal')
                        ->formatStateUsing(fn ($state) => Money::format($state))
                        ->prefix('$ ')
                        ->weight(FontWeight::Bold),
                    TextEntry::make('commission_amount')
                        ->label('Commission (Separate)')
                        ->formatStateUsing(fn ($state) => Money::format($state))
                        ->prefix('$ ')
                        ->visible(fn ($record) => $record->commission_amount > 0),
                    TextEntry::make('total')
                        ->label('Total')
                        ->formatStateUsing(fn ($state) => Money::format($state))
                        ->prefix('$ ')
                        ->weight(FontWeight::Bold)
                        ->color('success'),
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
                        ->label('Client Notes')
                        ->placeholder('No client notes.')
                        ->columnSpanFull()
                        ->markdown(),
                    TextEntry::make('internal_notes')
                        ->label('Internal Notes')
                        ->placeholder('No internal notes.')
                        ->columnSpanFull(),
                ]),
        ];
    }
}
