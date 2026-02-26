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
                    ->label(__('forms.labels.name'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('type')
                    ->label(__('forms.labels.type'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('bankAccount.account_name')
                    ->label(__('forms.labels.bank_account'))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('fee_type')
                    ->label(__('forms.labels.fee_type'))
                    ->badge(),
                TextColumn::make('processing_time')
                    ->label(__('forms.labels.processing'))
                    ->badge(),
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
                SelectFilter::make('type')
                    ->label(__('forms.labels.type'))
                    ->options(PaymentMethodType::class),
                SelectFilter::make('fee_type')
                    ->label(__('forms.labels.fee_type'))
                    ->options(FeeType::class),
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
            ->defaultSort('name', 'asc')
            ->emptyStateHeading('No payment methods')
            ->emptyStateDescription('Create your first payment method to start managing payments.')
            ->emptyStateIcon('heroicon-o-credit-card');
    }
}
