<?php

namespace App\Filament\Resources\CRM\Companies\RelationManagers;

use App\Domain\Catalog\Models\Category;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CategoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'categories';

    protected static ?string $title = 'Product Categories / Specialties';

    protected static ?string $recordTitleAttribute = 'name';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('full_path')
                    ->label('Category')
                    ->weight('bold'),
                TextColumn::make('sku_prefix')
                    ->label('SKU Prefix')
                    ->badge()
                    ->color('primary')
                    ->placeholder('—'),
                TextColumn::make('products_count')
                    ->label('Products')
                    ->counts('products')
                    ->alignCenter(),
                TextColumn::make('pivot.notes')
                    ->label('Notes')
                    ->limit(50)
                    ->placeholder('—'),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Add Category')
                    ->visible(fn () => auth()->user()?->can('edit-companies'))
                    ->preloadRecordSelect()
                    ->recordSelectOptionsQuery(fn ($query) => $query->active()->orderBy('name'))
                    ->recordTitle(fn (Category $record): string => $record->full_path)
                    ->form(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        Textarea::make('notes')
                            ->label('Notes')
                            ->rows(2)
                            ->maxLength(1000)
                            ->placeholder('E.g., Primary specialty, certified manufacturer, etc.'),
                    ]),
            ])
            ->recordActions([
                DetachAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-companies')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make()
                        ->visible(fn () => auth()->user()?->can('edit-companies')),
                ]),
            ])
            ->emptyStateHeading('No categories assigned')
            ->emptyStateDescription('Assign product categories to indicate what this company specializes in or purchases.')
            ->emptyStateIcon('heroicon-o-squares-2x2');
    }
}
