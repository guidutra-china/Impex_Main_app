<?php

namespace App\Filament\Resources\Catalog\Categories\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Category')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn ($record) => $record->parent ? $record->full_path : null),
                TextColumn::make('parent.name')
                    ->label('Parent')
                    ->placeholder('Root')
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query
                            ->leftJoin('categories as parent', 'categories.parent_id', '=', 'parent.id')
                            ->orderBy('parent.name', $direction)
                            ->select('categories.*');
                    })
                    ->searchable(),
                TextColumn::make('sku_prefix')
                    ->label('SKU Prefix')
                    ->badge()
                    ->color('primary')
                    ->placeholder('—'),
                TextColumn::make('children_count')
                    ->label('Subcategories')
                    ->counts('children')
                    ->alignCenter(),
                TextColumn::make('products_count')
                    ->label('Products')
                    ->counts('products')
                    ->alignCenter(),
                TextColumn::make('companies_count')
                    ->label('Companies')
                    ->counts('companies')
                    ->alignCenter(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->alignCenter(),
                TextColumn::make('sort_order')
                    ->label('Order')
                    ->alignCenter()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('is_active')
                    ->label('Status')
                    ->options([
                        '1' => 'Active',
                        '0' => 'Inactive',
                    ]),
                SelectFilter::make('parent_id')
                    ->label('Parent')
                    ->relationship('parent', 'name')
                    ->searchable()
                    ->preload(),
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
            ->persistSortInSession()
            ->defaultSort('name', 'asc')
            ->emptyStateHeading('No categories')
            ->emptyStateDescription('Create categories to organize your products and generate SKU prefixes.')
            ->emptyStateIcon('heroicon-o-squares-2x2');
    }
}
