<?php

namespace App\Filament\Resources\ProformaInvoices\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;

class ProformaInvoiceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('ProformaInvoice')
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
            Section::make('Invoice Identity')
                ->schema([
                    TextEntry::make('reference')
                        ->label('Reference')
                        ->weight(FontWeight::Bold)
                        ->copyable(),
                    TextEntry::make('status')
                        ->label('Status')
                        ->badge(),
                    TextEntry::make('inquiry.reference')
                        ->label('Inquiry')
                        ->weight(FontWeight::Bold)
                        ->url(fn ($record) => $record->inquiry_id
                            ? route('filament.admin.resources.inquiries.view', $record->inquiry_id)
                            : null
                        )
                        ->color('primary'),
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

            Section::make('Dates')
                ->schema([
                    TextEntry::make('issue_date')
                        ->label('Issue Date')
                        ->date('d/m/Y'),
                    TextEntry::make('valid_until')
                        ->label('Valid Until')
                        ->date('d/m/Y')
                        ->placeholder('—')
                        ->color(fn ($record) => $record->valid_until && $record->valid_until->isPast() ? 'danger' : null),
                    TextEntry::make('validity_days')
                        ->label('Validity')
                        ->suffix(' days')
                        ->placeholder('—'),
                ])
                ->columns(3),
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

            Section::make('Linked Quotations')
                ->schema([
                    TextEntry::make('quotations.reference')
                        ->label('Quotations')
                        ->badge()
                        ->color('info')
                        ->placeholder('No quotations linked.'),
                ]),
        ];
    }

    protected static function confirmationTab(): array
    {
        return [
            Section::make('Client Confirmation')
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
                    TextEntry::make('subtotal')
                        ->label('Total (Client)')
                        ->formatStateUsing(fn ($state) => number_format($state / 100, 2))
                        ->prefix('$ ')
                        ->weight(FontWeight::Bold)
                        ->color('success'),
                    TextEntry::make('cost_total')
                        ->label('Total Cost')
                        ->formatStateUsing(fn ($state) => number_format($state / 100, 2))
                        ->prefix('$ ')
                        ->weight(FontWeight::Bold)
                        ->color('danger'),
                    TextEntry::make('margin')
                        ->label('Margin')
                        ->suffix('%')
                        ->weight(FontWeight::Bold)
                        ->color(fn ($state) => $state > 0 ? 'success' : 'danger'),
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
