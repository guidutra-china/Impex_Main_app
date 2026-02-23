<?php

namespace App\Filament\Resources\Catalog\Tags;

use App\Domain\Catalog\Models\Tag;
use App\Filament\Resources\Catalog\Tags\Pages\CreateTag;
use App\Filament\Resources\Catalog\Tags\Pages\EditTag;
use App\Filament\Resources\Catalog\Tags\Pages\ListTags;
use App\Filament\Resources\Catalog\Tags\Schemas\TagForm;
use App\Filament\Resources\Catalog\Tags\Tables\TagsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class TagResource extends Resource
{
    protected static ?string $model = Tag::class;

    protected static UnitEnum|string|null $navigationGroup = 'Catalog';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-tag';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Tags';

    protected static ?string $slug = 'catalog/tags';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-categories') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return TagForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TagsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTags::route('/'),
            'create' => CreateTag::route('/create'),
            'edit' => EditTag::route('/{record}/edit'),
        ];
    }
}
