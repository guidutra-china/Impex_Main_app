<?php

namespace App\Filament\Resources\SupplierQuotations;

use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use App\Filament\Resources\SupplierQuotations\Pages\CreateSupplierQuotation;
use App\Filament\Resources\SupplierQuotations\Pages\EditSupplierQuotation;
use App\Filament\Resources\SupplierQuotations\Pages\ListSupplierQuotations;
use App\Filament\Resources\SupplierQuotations\Pages\ViewSupplierQuotation;
use App\Filament\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\SupplierQuotations\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\SupplierQuotations\Schemas\SupplierQuotationForm;
use App\Filament\Resources\SupplierQuotations\Schemas\SupplierQuotationInfolist;
use App\Filament\Resources\SupplierQuotations\Tables\SupplierQuotationsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class SupplierQuotationResource extends Resource
{
    protected static ?string $model = SupplierQuotation::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'supplier-quotations';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-supplier-quotations') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return SupplierQuotationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SupplierQuotationsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SupplierQuotationInfolist::configure($schema);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
            DocumentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupplierQuotations::route('/'),
            'create' => CreateSupplierQuotation::route('/create'),
            'edit' => EditSupplierQuotation::route('/{record}/edit'),
            'view' => ViewSupplierQuotation::route('/{record}'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.resources.supplier_quotations');
    }

    public static function getModelLabel(): string
    {
        return __('navigation.models.supplier_quotation');
    }

    public static function getPluralModelLabel(): string
    {
        return __('navigation.models.supplier_quotations');
    }
}
