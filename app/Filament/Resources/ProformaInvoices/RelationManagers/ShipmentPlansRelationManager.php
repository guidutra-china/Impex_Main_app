<?php

namespace App\Filament\Resources\ProformaInvoices\RelationManagers;

use App\Domain\Infrastructure\Support\Money;
use App\Domain\Planning\Enums\ShipmentPlanStatus;
use App\Domain\Planning\Models\ShipmentPlanItem;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class ShipmentPlansRelationManager extends RelationManager
{
    protected static string $relationship = 'shipmentPlanItems';

    protected static ?string $title = 'Shipment Plans';

    protected static BackedEnum|string|null $icon = 'heroicon-o-clipboard-document-check';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('shipmentPlan.reference')
                    ->label(__('forms.labels.shipment_plan'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('shipmentPlan.status')
                    ->label(__('forms.labels.status'))
                    ->badge(),
                TextColumn::make('proformaInvoiceItem.product.name')
                    ->label(__('forms.labels.product'))
                    ->default(fn ($record) => $record->proformaInvoiceItem?->description ?? '—')
                    ->limit(30),
                TextColumn::make('quantity')
                    ->label(__('forms.labels.qty'))
                    ->alignCenter()
                    ->weight('bold'),
                TextColumn::make('unit_price')
                    ->label(__('forms.labels.unit_price'))
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->alignEnd(),
                TextColumn::make('line_total')
                    ->label(__('forms.labels.total'))
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->alignEnd()
                    ->weight('bold'),
                TextColumn::make('shipmentPlan.planned_shipment_date')
                    ->label(__('forms.labels.planned_etd'))
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('—'),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn ($record) => route('filament.admin.resources.shipment-plans.view', $record->shipment_plan_id)),
            ])
            ->headerActions([
                Action::make('create_shipment_plan')
                    ->label('New Shipment Plan')
                    ->icon('heroicon-o-plus')
                    ->color('primary')
                    ->visible(fn () => auth()->user()?->can('create-shipment-plans'))
                    ->url(fn () => route('filament.admin.resources.shipment-plans.create')),
            ])
            ->emptyStateHeading('No shipment plans')
            ->emptyStateDescription('Items from this PI have not been added to any shipment plan yet.')
            ->emptyStateIcon('heroicon-o-clipboard-document-check')
            ->defaultSort('shipmentPlan.planned_shipment_date', 'asc');
    }
}
