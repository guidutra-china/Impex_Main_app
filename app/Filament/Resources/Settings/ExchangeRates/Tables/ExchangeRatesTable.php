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
                    ->label('Base')
                    ->badge()
                    ->color('primary')
                    ->sortable(),
                TextColumn::make('targetCurrency.code')
                    ->label('Target')
                    ->badge()
                    ->color('info')
                    ->sortable(),
                TextColumn::make('rate')
                    ->label('Rate')
                    ->numeric(8)
                    ->sortable(),
                TextColumn::make('inverse_rate')
                    ->label('Inverse')
                    ->numeric(8)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('date')
                    ->label('Date')
                    ->date('Y-m-d')
                    ->sortable(),
                TextColumn::make('source')
                    ->label('Source')
                    ->badge(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
                TextColumn::make('source_name')
                    ->label('Source Name')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('createdBy.name')
                    ->label('Created By')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(ExchangeRateStatus::class),
                SelectFilter::make('source')
                    ->label('Source')
                    ->options(ExchangeRateSource::class),
                SelectFilter::make('base_currency_id')
                    ->label('Base Currency')
                    ->relationship('baseCurrency', 'code'),
                SelectFilter::make('target_currency_id')
                    ->label('Target Currency')
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
            ->defaultSort('date', 'desc')
            ->emptyStateHeading('No exchange rates')
            ->emptyStateDescription('Create your first exchange rate to start tracking currency conversions.')
            ->emptyStateIcon('heroicon-o-arrows-right-left');
    }
}
