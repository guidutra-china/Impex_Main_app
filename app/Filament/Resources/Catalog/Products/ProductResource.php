<?php

namespace App\Filament\Resources\Catalog\Products;

use App\Domain\Catalog\Models\Product;
use App\Filament\Resources\Catalog\Products\Pages\CreateProduct;
use App\Filament\Resources\Catalog\Products\Pages\EditProduct;
use App\Filament\Resources\Catalog\Products\Pages\ListProducts;
use App\Filament\Resources\Catalog\Products\Pages\ViewProduct;
use App\Filament\Resources\Catalog\Products\Schemas\ProductForm;
use App\Filament\Resources\Catalog\Products\Schemas\ProductInfolist;
use App\Filament\Resources\Catalog\Products\Tables\ProductsTable;
use App\Filament\Resources\Catalog\Products\RelationManagers\ClientsRelationManager;
use App\Filament\Resources\Catalog\Products\RelationManagers\SuppliersRelationManager;
use App\Filament\Resources\Catalog\Products\RelationManagers\AttributeValuesRelationManager;
use App\Filament\Resources\Catalog\Products\RelationManagers\VariantsRelationManager;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-cube';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'catalog/products';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-products') ?? false;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'sku', 'brand', 'model_number', 'hs_code'];
    }

    public static function form(Schema $schema): Schema
    {
        return ProductForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ProductInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            AttributeValuesRelationManager::class,
            VariantsRelationManager::class,
            SuppliersRelationManager::class,
            ClientsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'view' => ViewProduct::route('/{record}'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.catalog');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.resources.products');
    }
}
