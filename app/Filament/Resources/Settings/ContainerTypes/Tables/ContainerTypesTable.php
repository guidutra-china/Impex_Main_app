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
                    ->label('Code')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('primary')
                    ->weight('bold'),
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('length_ft')
                    ->label('L (ft)')
                    ->numeric(2)
                    ->alignCenter(),
                TextColumn::make('width_ft')
                    ->label('W (ft)')
                    ->numeric(2)
                    ->alignCenter(),
                TextColumn::make('height_ft')
                    ->label('H (ft)')
                    ->numeric(2)
                    ->alignCenter(),
                TextColumn::make('max_weight_kg')
                    ->label('Max Weight (kg)')
                    ->numeric(0)
                    ->alignEnd(),
                TextColumn::make('cubic_capacity_cbm')
                    ->label('CBM')
                    ->numeric(2)
                    ->alignEnd(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->alignCenter(),
            ])
            ->filters([
                SelectFilter::make('is_active')
                    ->label('Status')
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
            ->persistSortInSession()
            ->defaultSort('code', 'asc')
            ->emptyStateHeading('No container types')
            ->emptyStateDescription('Create your first container type to manage shipping logistics.')
            ->emptyStateIcon('heroicon-o-cube');
    }
}
