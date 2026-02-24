<?php

namespace App\Filament\Resources\Shipments\Schemas;

use App\Domain\Infrastructure\Support\Money;
use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ShipmentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Section::make('Shipment Information')
                ->schema([
                    TextEntry::make('reference')
                        ->copyable()
                        ->weight('bold'),
                    TextEntry::make('company.name')
                        ->label('Client'),
                    TextEntry::make('status')
                        ->badge(),
                    TextEntry::make('transport_mode')
                        ->badge()
                        ->placeholder('—'),
                    TextEntry::make('container_type')
                        ->badge()
                        ->placeholder('—'),
                    TextEntry::make('currency_code')
                        ->label('Currency')
                        ->badge()
                        ->color('gray')
                        ->placeholder('—'),
                    TextEntry::make('issue_date')
                        ->label('Document Issue Date')
                        ->date('d/m/Y')
                        ->placeholder('Not set'),
                ])
                ->columns(3),

            Section::make('Route & Transport')
                ->schema([
                    TextEntry::make('origin_port')
                        ->label('Port of Loading')
                        ->placeholder('—'),
                    TextEntry::make('destination_port')
                        ->label('Port of Destination')
                        ->placeholder('—'),
                    TextEntry::make('vessel_name')
                        ->placeholder('—'),
                    TextEntry::make('bl_number')
                        ->label('B/L Number')
                        ->copyable()
                        ->placeholder('—'),
                    TextEntry::make('container_number')
                        ->copyable()
                        ->placeholder('—'),
                    TextEntry::make('voyage_number')
                        ->placeholder('—'),
                ])
                ->columns(3)
                ->collapsible(),

            Section::make('Carrier & Booking')
                ->schema([
                    TextEntry::make('carrier')
                        ->placeholder('—'),
                    TextEntry::make('freight_forwarder')
                        ->placeholder('—'),
                    TextEntry::make('booking_number')
                        ->copyable()
                        ->placeholder('—'),
                ])
                ->columns(3)
                ->collapsible()
                ->collapsed(),

            Section::make('Dates')
                ->schema([
                    TextEntry::make('etd')
                        ->label('ETD (Estimated Departure)')
                        ->date('d/m/Y')
                        ->placeholder('—'),
                    TextEntry::make('eta')
                        ->label('ETA (Estimated Arrival)')
                        ->date('d/m/Y')
                        ->placeholder('—'),
                    TextEntry::make('actual_departure')
                        ->label('Actual Departure')
                        ->date('d/m/Y')
                        ->placeholder('—'),
                    TextEntry::make('actual_arrival')
                        ->label('Actual Arrival')
                        ->date('d/m/Y')
                        ->placeholder('—'),
                ])
                ->columns(4)
                ->collapsible(),

            Section::make('Weight & Volume')
                ->schema([
                    TextEntry::make('total_gross_weight')
                        ->label('Gross Weight')
                        ->suffix(' kg')
                        ->placeholder('—'),
                    TextEntry::make('total_net_weight')
                        ->label('Net Weight')
                        ->suffix(' kg')
                        ->placeholder('—'),
                    TextEntry::make('total_volume')
                        ->label('Volume')
                        ->suffix(' CBM')
                        ->placeholder('—'),
                    TextEntry::make('total_packages')
                        ->label('Packages')
                        ->placeholder('—'),
                ])
                ->columns(4)
                ->collapsible(),

            Section::make('References')
                ->schema([
                    TextEntry::make('proforma_invoice_references')
                        ->label('Proforma Invoices')
                        ->placeholder('No items added yet'),
                    TextEntry::make('purchase_order_references')
                        ->label('Purchase Orders')
                        ->placeholder('No items added yet'),
                    TextEntry::make('total_value')
                        ->label('Total Value')
                        ->formatStateUsing(fn ($state, $record) => ($record->currency_code ?? '') . ' ' . Money::format($state))
                        ->weight('bold'),
                ])
                ->columns(3),

            Section::make('Notes')
                ->schema([
                    TextEntry::make('notes')
                        ->placeholder('—')
                        ->columnSpanFull(),
                    TextEntry::make('internal_notes')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->collapsible()
                ->collapsed(),
        ]);
    }
}
