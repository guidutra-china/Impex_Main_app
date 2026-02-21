<?php

namespace App\Filament\Resources\Settings\PaymentMethods\Tables;

use App\Domain\Settings\Enums\FeeType;
use App\Domain\Settings\Enums\PaymentMethodType;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PaymentMethodsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('bankAccount.account_name')
                    ->label('Bank Account')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('fee_type')
                    ->label('Fee Type')
                    ->badge(),
                TextColumn::make('processing_time')
                    ->label('Processing')
                    ->badge(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->alignCenter(),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Type')
                    ->options(PaymentMethodType::class),
                SelectFilter::make('fee_type')
                    ->label('Fee Type')
                    ->options(FeeType::class),
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
            ->defaultSort('name', 'asc')
            ->emptyStateHeading('No payment methods')
            ->emptyStateDescription('Create your first payment method to start managing payments.')
            ->emptyStateIcon('heroicon-o-credit-card');
    }
}
