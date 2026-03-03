<?php

namespace App\Filament\Resources\ShipmentPlans\Schemas;

use App\Domain\Infrastructure\Support\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ShipmentPlanInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Section::make(__('forms.sections.shipment_plan_information'))
                ->schema([
                    TextEntry::make('reference')
                        ->copyable()
                        ->weight('bold'),
                    TextEntry::make('supplierCompany.name')
                        ->label(__('forms.labels.supplier')),
                    TextEntry::make('status')
                        ->badge(),
                    TextEntry::make('currency_code')
                        ->label(__('forms.labels.currency'))
                        ->badge()
                        ->color('gray'),
                    TextEntry::make('container_type')
                        ->label(__('forms.labels.container_type'))
                        ->placeholder('—'),
                ])
                ->columns(3)
                ->columnSpanFull(),

            Section::make(__('forms.sections.planned_dates'))
                ->schema([
                    TextEntry::make('planned_shipment_date')
                        ->label(__('forms.labels.planned_shipment_date'))
                        ->date('d/m/Y')
                        ->placeholder('—'),
                    TextEntry::make('planned_eta')
                        ->label(__('forms.labels.planned_eta'))
                        ->date('d/m/Y')
                        ->placeholder('—'),
                ])
                ->columns(2)
                ->columnSpanFull(),

            Section::make(__('forms.sections.capacity'))
                ->schema([
                    TextEntry::make('max_cbm')
                        ->label(__('forms.labels.max_cbm'))
                        ->suffix(' CBM')
                        ->placeholder('—'),
                    TextEntry::make('max_weight')
                        ->label(__('forms.labels.max_weight'))
                        ->suffix(' kg')
                        ->placeholder('—'),
                    TextEntry::make('total')
                        ->label(__('forms.labels.total_value'))
                        ->formatStateUsing(fn ($state, $record) => ($record->currency_code ?? '') . ' ' . Money::format($state))
                        ->weight('bold'),
                ])
                ->columns(3)
                ->columnSpanFull(),

            Section::make(__('forms.sections.linked_shipment'))
                ->schema([
                    TextEntry::make('shipment.reference')
                        ->label(__('forms.labels.shipment'))
                        ->url(fn ($record) => $record->shipment
                            ? route('filament.admin.resources.shipments.view', $record->shipment_id)
                            : null)
                        ->color('primary')
                        ->placeholder(__('forms.placeholders.not_yet_shipped')),
                ])
                ->visible(fn ($record) => $record->shipment_id !== null)
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
        ]);
    }
}
