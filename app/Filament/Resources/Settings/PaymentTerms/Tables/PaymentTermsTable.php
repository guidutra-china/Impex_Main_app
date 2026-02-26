<?php

namespace App\Filament\Resources\Settings\PaymentTerms\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PaymentTermsTable
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
                TextColumn::make('description')
                    ->label(__('forms.labels.description'))
                    ->limit(60)
                    ->toggleable(),
                TextColumn::make('stages_count')
                    ->label(__('forms.labels.stages'))
                    ->counts('stages')
                    ->alignCenter()
                    ->badge()
                    ->color('info'),
                IconColumn::make('is_default')
                    ->label(__('forms.labels.default'))
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
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->defaultSort('name', 'asc')
            ->emptyStateHeading('No payment terms')
            ->emptyStateDescription('Create your first payment term to define payment schedules.')
            ->emptyStateIcon('heroicon-o-clock');
    }
}
