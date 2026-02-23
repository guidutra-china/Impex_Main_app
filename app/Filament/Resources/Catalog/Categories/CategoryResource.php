<?php

namespace App\Filament\Resources\Catalog\Categories;

use App\Domain\Catalog\Models\Category;
use App\Filament\Resources\Catalog\Categories\Pages\CreateCategory;
use App\Filament\Resources\Catalog\Categories\Pages\EditCategory;
use App\Filament\Resources\Catalog\Categories\Pages\ListCategories;
use App\Filament\Resources\Catalog\Categories\RelationManagers\CategoryAttributesRelationManager;
use App\Filament\Resources\Catalog\Categories\RelationManagers\InheritedAttributesRelationManager;
use App\Filament\Resources\Catalog\Categories\Schemas\CategoryForm;
use App\Filament\Resources\Catalog\Categories\Tables\CategoriesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static UnitEnum|string|null $navigationGroup = 'Catalog';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Categories';

    protected static ?string $slug = 'catalog/categories';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-categories') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return CategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CategoriesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            CategoryAttributesRelationManager::class,
            InheritedAttributesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCategories::route('/'),
            'create' => CreateCategory::route('/create'),
            'edit' => EditCategory::route('/{record}/edit'),
        ];
    }
}
