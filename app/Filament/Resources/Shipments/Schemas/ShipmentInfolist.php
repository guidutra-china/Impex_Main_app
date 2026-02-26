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

            Section::make(__('forms.sections.shipment_information'))
                ->schema([
                    TextEntry::make('reference')
                        ->copyable()
                        ->weight('bold'),
                    TextEntry::make('company.name')
                        ->label(__('forms.labels.client')),
                    TextEntry::make('status')
                        ->badge(),
                    TextEntry::make('transport_mode')
                        ->badge()
                        ->placeholder('—'),
                    TextEntry::make('container_type')
                        ->badge()
                        ->placeholder('—'),
                    TextEntry::make('currency_code')
                        ->label(__('forms.labels.currency'))
                        ->badge()
                        ->color('gray')
                        ->placeholder('—'),
                    TextEntry::make('issue_date')
                        ->label(__('forms.labels.document_issue_date'))
                        ->date('d/m/Y')
                        ->placeholder(__('forms.placeholders.not_set')),
                ])
                ->columns(3)
                ->columnSpanFull(),

            Section::make(__('forms.sections.route_transport'))
                ->schema([
                    TextEntry::make('origin_port')
                        ->label(__('forms.labels.port_of_loading'))
                        ->placeholder('—'),
                    TextEntry::make('destination_port')
                        ->label(__('forms.labels.port_of_destination'))
                        ->placeholder('—'),
                    TextEntry::make('vessel_name')
                        ->placeholder('—'),
                    TextEntry::make('bl_number')
                        ->label(__('forms.labels.bl_number'))
                        ->copyable()
                        ->placeholder('—'),
                    TextEntry::make('container_number')
                        ->copyable()
                        ->placeholder('—'),
                    TextEntry::make('voyage_number')
                        ->placeholder('—'),
                ])
                ->columns(3)
                ->collapsible()
                ->columnSpanFull(),

            Section::make(__('forms.sections.carrier_booking'))
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
                ->collapsed()
                ->columnSpanFull(),

            Section::make(__('forms.sections.dates'))
                ->schema([
                    TextEntry::make('etd')
                        ->label(__('forms.labels.etd_estimated_departure'))
                        ->date('d/m/Y')
                        ->placeholder('—'),
                    TextEntry::make('eta')
                        ->label(__('forms.labels.eta_estimated_arrival'))
                        ->date('d/m/Y')
                        ->placeholder('—'),
                    TextEntry::make('actual_departure')
                        ->label(__('forms.labels.actual_departure'))
                        ->date('d/m/Y')
                        ->placeholder('—'),
                    TextEntry::make('actual_arrival')
                        ->label(__('forms.labels.actual_arrival'))
                        ->date('d/m/Y')
                        ->placeholder('—'),
                ])
                ->columns(4)
                ->collapsible()
                ->columnSpanFull(),

            Section::make(__('forms.sections.weight_volume'))
                ->schema([
                    TextEntry::make('total_gross_weight')
                        ->label(__('forms.labels.gross_weight'))
                        ->suffix(' kg')
                        ->placeholder('—'),
                    TextEntry::make('total_net_weight')
                        ->label(__('forms.labels.net_weight'))
                        ->suffix(' kg')
                        ->placeholder('—'),
                    TextEntry::make('total_volume')
                        ->label(__('forms.labels.volume'))
                        ->suffix(' CBM')
                        ->placeholder('—'),
                    TextEntry::make('total_packages')
                        ->label(__('forms.labels.packages'))
                        ->placeholder('—'),
                ])
                ->columns(4)
                ->collapsible()
                ->columnSpanFull(),

            Section::make(__('forms.sections.references'))
                ->schema([
                    TextEntry::make('proforma_invoice_references')
                        ->label(__('forms.labels.proforma_invoices'))
                        ->placeholder(__('forms.placeholders.no_items_added_yet')),
                    TextEntry::make('purchase_order_references')
                        ->label(__('forms.labels.purchase_orders'))
                        ->placeholder(__('forms.placeholders.no_items_added_yet')),
                    TextEntry::make('total_value')
                        ->label(__('forms.labels.total_value'))
                        ->formatStateUsing(fn ($state, $record) => ($record->currency_code ?? '') . ' ' . Money::format($state))
                        ->weight('bold'),
                ])
                ->columns(3)
                ->columnSpanFull(),

            Section::make(__('forms.sections.notes'))
                ->schema([
                    TextEntry::make('notes')
                        ->placeholder('—')
                        ->columnSpanFull(),
                    TextEntry::make('internal_notes')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->collapsible()
                ->collapsed()
                ->columnSpanFull(),
        ]);
    }
}
