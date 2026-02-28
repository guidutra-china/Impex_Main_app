<?php

namespace App\Filament\Resources\Catalog\Categories\RelationManagers;

use App\Domain\Catalog\Enums\ProductStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'products';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('forms.labels.products');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sku')
                    ->label(__('forms.labels.sku'))
                    ->badge()
                    ->color('primary')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label(__('forms.labels.product'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('client_name')
                    ->label(__('forms.labels.client_name'))
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label(__('forms.labels.status'))
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('forms.labels.status'))
                    ->options(ProductStatus::class),
            ])
            ->defaultSort('name', 'asc')
            ->emptyStateHeading(__('forms.placeholders.no_products_in_category'))
            ->emptyStateDescription(__('forms.descriptions.products_in_this_category'))
            ->emptyStateIcon('heroicon-o-cube');
    }
}
