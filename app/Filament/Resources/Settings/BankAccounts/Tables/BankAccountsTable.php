<?php

namespace App\Filament\Resources\Settings\BankAccounts\Tables;

use App\Domain\Settings\Enums\BankAccountStatus;
use App\Domain\Settings\Enums\BankAccountType;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class BankAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('account_name')
                    ->label(__('forms.labels.account_name'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('bank_name')
                    ->label(__('forms.labels.bank'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('currency.code')
                    ->label(__('forms.labels.currency'))
                    ->badge()
                    ->color('primary'),
                TextColumn::make('account_type')
                    ->label(__('forms.labels.type'))
                    ->badge(),
                TextColumn::make('status')
                    ->label(__('forms.labels.status'))
                    ->badge(),
                TextColumn::make('formatted_current_balance')
                    ->label(__('forms.labels.current_balance'))
                    ->alignEnd(),
                TextColumn::make('formatted_available_balance')
                    ->label(__('forms.labels.available_balance'))
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('swift_code')
                    ->label(__('forms.labels.swift'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label(__('forms.labels.updated'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('forms.labels.status'))
                    ->options(BankAccountStatus::class),
                SelectFilter::make('account_type')
                    ->label(__('forms.labels.type'))
                    ->options(BankAccountType::class),
                SelectFilter::make('currency_id')
                    ->label(__('forms.labels.currency'))
                    ->relationship('currency', 'code'),
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
            ->defaultSort('account_name', 'asc')
            ->emptyStateHeading('No bank accounts')
            ->emptyStateDescription('Create your first bank account to manage financial operations.')
            ->emptyStateIcon('heroicon-o-building-library');
    }
}
