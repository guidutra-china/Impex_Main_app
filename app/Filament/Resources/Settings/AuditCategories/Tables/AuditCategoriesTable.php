<?php

namespace App\Filament\Resources\Settings\AuditCategories\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AuditCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')
                    ->label(__('forms.labels.hash'))
                    ->sortable()
                    ->alignCenter()
                    ->width('50px'),
                TextColumn::make('name')
                    ->label(__('forms.labels.category'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn ($record) => $record->description),
                TextColumn::make('weight')
                    ->label(__('forms.labels.weight'))
                    ->suffix('%')
                    ->sortable()
                    ->alignCenter()
                    ->badge()
                    ->color('primary'),
                TextColumn::make('criteria_count')
                    ->label(__('forms.labels.criteria'))
                    ->counts('criteria')
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
            ->defaultSort('sort_order', 'asc')
            ->emptyStateHeading('No audit categories')
            ->emptyStateDescription('Create categories and criteria to start auditing suppliers.')
            ->emptyStateIcon('heroicon-o-clipboard-document-check');
    }
}
