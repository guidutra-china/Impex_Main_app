<?php

namespace App\Filament\Resources\Settings\ContainerTypes\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ContainerTypesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label(__('forms.labels.code'))
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('primary')
                    ->weight('bold'),
                TextColumn::make('name')
                    ->label(__('forms.labels.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('length_ft')
                    ->label(__('forms.labels.l_ft'))
                    ->numeric(2)
                    ->alignCenter(),
                TextColumn::make('width_ft')
                    ->label(__('forms.labels.w_ft'))
                    ->numeric(2)
                    ->alignCenter(),
                TextColumn::make('height_ft')
                    ->label(__('forms.labels.h_ft'))
                    ->numeric(2)
                    ->alignCenter(),
                TextColumn::make('max_weight_kg')
                    ->label(__('forms.labels.max_weight_kg'))
                    ->numeric(0)
                    ->alignEnd(),
                TextColumn::make('cubic_capacity_cbm')
                    ->label(__('forms.labels.cbm'))
                    ->numeric(2)
                    ->alignEnd(),
                IconColumn::make('is_active')
                    ->label(__('forms.labels.active'))
                    ->boolean()
                    ->alignCenter(),
            ])
            ->filters([
                SelectFilter::make('is_active')
                    ->label(__('forms.labels.status'))
                    ->options([
                        '1' => 'Active',
                        '0' => 'Inactive',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->defaultSort('code', 'asc')
            ->emptyStateHeading('No container types')
            ->emptyStateDescription('Create your first container type to manage shipping logistics.')
            ->emptyStateIcon('heroicon-o-cube');
    }
}
