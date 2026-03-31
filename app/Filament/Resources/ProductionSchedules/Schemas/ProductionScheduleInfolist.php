<?php

namespace App\Filament\Resources\ProductionSchedules\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductionScheduleInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Section::make(__('forms.sections.production_schedule_information'))
                ->schema([
                    TextEntry::make('reference')
                        ->copyable()
                        ->weight('bold'),
                    TextEntry::make('proformaInvoice.reference')
                        ->label(__('forms.labels.proforma_invoice'))
                        ->url(fn ($record) => $record->proformaInvoice
                            ? route('filament.admin.resources.proforma-invoices.view', $record->proforma_invoice_id)
                            : null)
                        ->color('primary'),
                    TextEntry::make('proformaInvoice.company.name')
                        ->label(__('forms.labels.supplier')),
                    TextEntry::make('version')
                        ->label(__('forms.labels.version'))
                        ->badge()
                        ->color('gray'),
                    TextEntry::make('received_date')
                        ->label(__('forms.labels.received_date'))
                        ->date('d/m/Y')
                        ->placeholder('—'),
                    TextEntry::make('created_at')
                        ->label(__('forms.labels.created_at'))
                        ->dateTime('d/m/Y H:i'),
                ])
                ->columns(3)
                ->columnSpanFull(),

            Section::make(__('forms.sections.notes'))
                ->schema([
                    TextEntry::make('notes')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->collapsible()
                ->collapsed()
                ->columnSpanFull(),

            Section::make(__('forms.sections.production_summary'))
                ->schema([
                    TextEntry::make('total_quantity')
                        ->label('Total Planned')
                        ->getStateUsing(fn ($record) => $record->total_quantity)
                        ->numeric(),
                    TextEntry::make('total_actual_quantity')
                        ->label('Total Produced')
                        ->getStateUsing(fn ($record) => $record->total_actual_quantity)
                        ->numeric()
                        ->badge()
                        ->color(fn ($state, $record) => match (true) {
                            $state >= $record->total_quantity => 'success',
                            $state > 0 => 'warning',
                            default => 'gray',
                        }),
                    TextEntry::make('completion_percentage')
                        ->label('Completion')
                        ->getStateUsing(fn ($record) => $record->total_quantity > 0
                            ? round(($record->total_actual_quantity / $record->total_quantity) * 100, 1) . '%'
                            : '—'),
                    ViewEntry::make('shipment_ready_by_item')
                        ->label('Shipment-Ready by Item')
                        ->view('filament.production-schedule.shipment-ready-summary')
                        ->columnSpanFull(),
                ])
                ->columns(3)
                ->collapsible()
                ->columnSpanFull(),

            Section::make('Production Actuals')
                ->schema([
                    ViewEntry::make('actuals_grid')
                        ->view('filament.production-schedule.actuals-grid-entry')
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
        ]);
    }
}
