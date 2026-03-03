<?php

namespace App\Filament\Resources\ProductionSchedules\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProductionSchedulesTable
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
                TextColumn::make('proformaInvoice.reference')
                    ->label(__('forms.labels.proforma_invoice'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('proformaInvoice.company.name')
                    ->label(__('forms.labels.supplier'))
                    ->searchable()
                    ->sortable()
                    ->limit(30),
                TextColumn::make('version')
                    ->label(__('forms.labels.version'))
                    ->badge()
                    ->color('gray')
                    ->alignCenter(),
                TextColumn::make('entries_count')
                    ->label(__('forms.labels.entries'))
                    ->counts('entries')
                    ->alignCenter(),
                TextColumn::make('received_date')
                    ->label(__('forms.labels.received_date'))
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('proforma_invoice_id')
                    ->label(__('forms.labels.proforma_invoice'))
                    ->relationship('proformaInvoice', 'reference')
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
            ->emptyStateHeading('No production schedules')
            ->emptyStateDescription('Create a production schedule to track supplier manufacturing progress.')
            ->emptyStateIcon('heroicon-o-calendar-days');
    }
}
