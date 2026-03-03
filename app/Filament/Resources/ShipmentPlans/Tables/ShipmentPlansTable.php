<?php

namespace App\Filament\Resources\ShipmentPlans\Tables;

use App\Domain\Infrastructure\Support\Money;
use App\Domain\Planning\Enums\ShipmentPlanStatus;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ShipmentPlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),
                TextColumn::make('supplierCompany.name')
                    ->label(__('forms.labels.supplier'))
                    ->searchable()
                    ->sortable()
                    ->limit(30),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('planned_shipment_date')
                    ->label(__('forms.labels.planned_etd'))
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('planned_eta')
                    ->label(__('forms.labels.planned_eta'))
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('items_count')
                    ->label(__('forms.labels.items'))
                    ->counts('items')
                    ->alignCenter(),
                TextColumn::make('total')
                    ->label(__('forms.labels.total_value'))
                    ->formatStateUsing(fn ($state, $record) => ($record->currency_code ?? '') . ' ' . Money::format($state))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('shipment.reference')
                    ->label(__('forms.labels.shipment'))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(ShipmentPlanStatus::class),
                SelectFilter::make('supplier_company_id')
                    ->label(__('forms.labels.supplier'))
                    ->relationship('supplierCompany', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No shipment plans')
            ->emptyStateDescription('Create a shipment plan to organize what will be shipped and when.')
            ->emptyStateIcon('heroicon-o-clipboard-document-check');
    }
}
