<?php

namespace App\Filament\Resources\Quotations;

use App\Domain\Quotations\Models\Quotation;
use App\Filament\Resources\Quotations\Pages\CreateQuotation;
use App\Filament\Resources\Quotations\Pages\EditQuotation;
use App\Filament\Resources\Quotations\Pages\ListQuotations;
use App\Filament\Resources\Quotations\Pages\ViewQuotation;
use App\Filament\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Quotations\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\Quotations\RelationManagers\VersionsRelationManager;
use App\Filament\Resources\Quotations\Schemas\QuotationForm;
use App\Filament\Resources\Quotations\Schemas\QuotationInfolist;
use App\Filament\Resources\Quotations\Tables\QuotationsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class QuotationResource extends Resource
{
    protected static ?string $model = Quotation::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'quotations';

    protected static ?string $recordTitleAttribute = 'reference';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-quotations') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['reference', 'company.name', 'notes'];
    }

    public static function form(Schema $schema): Schema
    {
        return QuotationForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return QuotationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return QuotationsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
            VersionsRelationManager::class,
            DocumentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQuotations::route('/'),
            'create' => CreateQuotation::route('/create'),
            'view' => ViewQuotation::route('/{record}'),
            'edit' => EditQuotation::route('/{record}/edit'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.resources.quotations');
    }
}
