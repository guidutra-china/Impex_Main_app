<?php

namespace App\Filament\Resources\Shipments\Tables;

use App\Domain\Infrastructure\Support\Money;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Enums\TransportMode;
use App\Filament\Actions\StatusTransitionActions;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class ShipmentsTable
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
                TextColumn::make('company.name')
                    ->label(__('forms.labels.client'))
                    ->searchable()
                    ->sortable()
                    ->limit(30),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('transport_mode')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('container_type')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('bl_number')
                    ->label(__('forms.labels.bl'))
                    ->searchable()
                    ->copyable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('container_number')
                    ->label(__('forms.labels.container'))
                    ->searchable()
                    ->copyable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('origin_port')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('destination_port')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('etd')
                    ->label(__('forms.labels.etd'))
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('eta')
                    ->label(__('forms.labels.eta'))
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('items_count')
                    ->label(__('forms.labels.items'))
                    ->counts('items')
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('responsible.name')
                    ->label(__('forms.labels.responsible'))
                    ->sortable()
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(ShipmentStatus::class),
                SelectFilter::make('transport_mode')
                    ->options(TransportMode::class),
                SelectFilter::make('company_id')
                    ->label(__('forms.labels.client'))
                    ->relationship('company', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('my_projects')
                    ->label(__('forms.labels.my_projects'))
                    ->toggle()
                    ->query(fn ($query) => $query->where('responsible_user_id', auth()->id())),
                TrashedFilter::make(),
            ])
            ->recordActions([
                StatusTransitionActions::make(ShipmentStatus::class),
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
            ->emptyStateHeading('No shipments')
            ->emptyStateDescription('Create a shipment to start tracking your exports.')
            ->emptyStateIcon('heroicon-o-truck');
    }
}
