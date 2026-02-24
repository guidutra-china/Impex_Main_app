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
                    ->label('Account Name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('bank_name')
                    ->label('Bank')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('currency.code')
                    ->label('Currency')
                    ->badge()
                    ->color('primary'),
                TextColumn::make('account_type')
                    ->label('Type')
                    ->badge(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
                TextColumn::make('formatted_current_balance')
                    ->label('Current Balance')
                    ->alignEnd(),
                TextColumn::make('formatted_available_balance')
                    ->label('Available Balance')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('swift_code')
                    ->label('SWIFT')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(BankAccountStatus::class),
                SelectFilter::make('account_type')
                    ->label('Type')
                    ->options(BankAccountType::class),
                SelectFilter::make('currency_id')
                    ->label('Currency')
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
