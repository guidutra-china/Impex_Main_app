<?php

namespace App\Filament\Resources\Catalog\Categories\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SubcategoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'children';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('forms.labels.subcategories');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('forms.labels.name'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('sku_prefix')
                    ->label(__('forms.labels.sku_prefix'))
                    ->badge()
                    ->color('primary')
                    ->placeholder('—'),
                TextColumn::make('products_count')
                    ->label(__('forms.labels.products'))
                    ->counts('products')
                    ->alignCenter(),
                TextColumn::make('companies_count')
                    ->label(__('forms.labels.companies'))
                    ->counts('companies')
                    ->alignCenter(),
                IconColumn::make('is_active')
                    ->label(__('forms.labels.active'))
                    ->boolean()
                    ->alignCenter(),
            ])
            ->defaultSort('name', 'asc')
            ->emptyStateHeading(__('forms.placeholders.no_subcategories'))
            ->emptyStateDescription(__('forms.descriptions.direct_subcategories'))
            ->emptyStateIcon('heroicon-o-squares-2x2');
    }
}
