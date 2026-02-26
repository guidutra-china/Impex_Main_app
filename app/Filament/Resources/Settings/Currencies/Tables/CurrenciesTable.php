<?php

namespace App\Filament\Resources\Settings\Currencies\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CurrenciesTable
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
                TextColumn::make('symbol')
                    ->label(__('forms.labels.symbol'))
                    ->alignCenter(),
                TextColumn::make('decimal_places')
                    ->label(__('forms.labels.decimals'))
                    ->alignCenter(),
                IconColumn::make('is_base')
                    ->label(__('forms.labels.base'))
                    ->boolean()
                    ->alignCenter(),
                IconColumn::make('is_active')
                    ->label(__('forms.labels.active'))
                    ->boolean()
                    ->alignCenter(),
                TextColumn::make('updated_at')
                    ->label(__('forms.labels.updated'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->defaultSort('code', 'asc')
            ->emptyStateHeading('No currencies')
            ->emptyStateDescription('Create your first currency to start managing multi-currency operations.')
            ->emptyStateIcon('heroicon-o-currency-dollar');
    }
}
