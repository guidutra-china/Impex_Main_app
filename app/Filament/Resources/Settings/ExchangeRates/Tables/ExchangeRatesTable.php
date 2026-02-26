<?php

namespace App\Filament\Resources\Settings\ExchangeRates\Tables;

use App\Domain\Settings\Enums\ExchangeRateSource;
use App\Domain\Settings\Enums\ExchangeRateStatus;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ExchangeRatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('baseCurrency.code')
                    ->label(__('forms.labels.base'))
                    ->badge()
                    ->color('primary')
                    ->sortable(),
                TextColumn::make('targetCurrency.code')
                    ->label(__('forms.labels.target'))
                    ->badge()
                    ->color('info')
                    ->sortable(),
                TextColumn::make('rate')
                    ->label(__('forms.labels.rate'))
                    ->numeric(8)
                    ->sortable(),
                TextColumn::make('inverse_rate')
                    ->label(__('forms.labels.inverse'))
                    ->numeric(8)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('date')
                    ->label(__('forms.labels.date'))
                    ->date('Y-m-d')
                    ->sortable(),
                TextColumn::make('source')
                    ->label(__('forms.labels.source'))
                    ->badge(),
                TextColumn::make('status')
                    ->label(__('forms.labels.status'))
                    ->badge(),
                TextColumn::make('source_name')
                    ->label(__('forms.labels.source_name'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('createdBy.name')
                    ->label(__('forms.labels.created_by'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label(__('forms.labels.created'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('forms.labels.status'))
                    ->options(ExchangeRateStatus::class),
                SelectFilter::make('source')
                    ->label(__('forms.labels.source'))
                    ->options(ExchangeRateSource::class),
                SelectFilter::make('base_currency_id')
                    ->label(__('forms.labels.base_currency'))
                    ->relationship('baseCurrency', 'code'),
                SelectFilter::make('target_currency_id')
                    ->label(__('forms.labels.target_currency'))
                    ->relationship('targetCurrency', 'code'),
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
            ->defaultSort('date', 'desc')
            ->emptyStateHeading('No exchange rates')
            ->emptyStateDescription('Create your first exchange rate to start tracking currency conversions.')
            ->emptyStateIcon('heroicon-o-arrows-right-left');
    }
}
