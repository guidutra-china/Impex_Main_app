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
                        Tabs\Tab::make('General')
                            ->icon('heroicon-o-information-circle')
                            ->schema(static::generalTab()),
                        Tabs\Tab::make('Commercial')
                            ->icon('heroicon-o-currency-dollar')
                            ->schema(static::commercialTab()),
                        Tabs\Tab::make('Notes')
                            ->icon('heroicon-o-chat-bubble-left-right')
                            ->schema(static::notesTab()),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    protected static function generalTab(): array
    {
        return [
            Section::make('Quotation Identity')
                ->schema([
                    TextEntry::make('reference')
                        ->label('Reference')
                        ->copyable(),
                    TextEntry::make('status')
                        ->label('Status')
                        ->badge(),
                    TextEntry::make('inquiry.reference')
                        ->label('Inquiry'),
                    TextEntry::make('currency_code')
                        ->label('Currency'),
                ])
                ->columns(2),

            Section::make('Supplier')
                ->schema([
                    TextEntry::make('company.name')
                        ->label('Supplier'),
                    TextEntry::make('contact.name')
                        ->label('Contact')
                        ->placeholder('—'),
                    TextEntry::make('supplier_reference')
                        ->label('Supplier Quotation Number')
                        ->placeholder('—'),
                ])
                ->columns(2),

            Section::make('Dates')
                ->schema([
                    TextEntry::make('requested_at')
                        ->label('Requested Date')
                        ->date('d/m/Y'),
                    TextEntry::make('received_at')
                        ->label('Received Date')
                        ->date('d/m/Y')
                        ->placeholder('Pending'),
                    TextEntry::make('valid_until')
                        ->label('Valid Until')
                        ->date('d/m/Y')
                        ->placeholder('—'),
                    TextEntry::make('creator.name')
                        ->label('Created By')
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
                    TextEntry::make('lead_time_days')
                        ->label('Lead Time')
                        ->suffix(' days')
                        ->placeholder('—'),
                    TextEntry::make('moq')
                        ->label('MOQ')
                        ->placeholder('—'),
                    TextEntry::make('incoterm')
                        ->label('Incoterm')
                        ->placeholder('—'),
                    TextEntry::make('paymentTerm.name')
                        ->label('Payment Terms')
                        ->placeholder('—'),
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
                        ->placeholder('—')
                        ->columnSpanFull(),
                    TextEntry::make('internal_notes')
                        ->label('Internal Notes')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),
        ];
    }
}
