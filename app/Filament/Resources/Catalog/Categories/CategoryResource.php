<?php

namespace App\Filament\Resources\Catalog\Categories;

use App\Domain\Catalog\Models\Category;
use App\Filament\Resources\Catalog\Categories\Pages\CreateCategory;
use App\Filament\Resources\Catalog\Categories\Pages\EditCategory;
use App\Filament\Resources\Catalog\Categories\Pages\ListCategories;
use App\Filament\Resources\Catalog\Categories\Pages\ViewCategory;
use App\Filament\Resources\Catalog\Categories\RelationManagers\CategoryAttributesRelationManager;
use App\Filament\Resources\Catalog\Categories\RelationManagers\CompaniesRelationManager;
use App\Filament\Resources\Catalog\Categories\RelationManagers\InheritedAttributesRelationManager;
use App\Filament\Resources\Catalog\Categories\RelationManagers\ProductsRelationManager;
use App\Filament\Resources\Catalog\Categories\RelationManagers\SubcategoriesRelationManager;
use App\Filament\Resources\Catalog\Categories\Schemas\CategoryForm;
use App\Filament\Resources\Catalog\Categories\Schemas\CategoryInfolist;
use App\Filament\Resources\Catalog\Categories\Tables\CategoriesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?int $navigationSort = 2;

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

    public static function infolist(Schema $schema): Schema
    {
        return CategoryInfolist::configure($schema);
    }

    public static function getRelations(): array
    {
        return [
            CompaniesRelationManager::class,
            ProductsRelationManager::class,
            SubcategoriesRelationManager::class,
            CategoryAttributesRelationManager::class,
            InheritedAttributesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCategories::route('/'),
            'create' => CreateCategory::route('/create'),
            'view' => ViewCategory::route('/{record}'),
            'edit' => EditCategory::route('/{record}/edit'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.catalog');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.resources.categories');
    }
}
