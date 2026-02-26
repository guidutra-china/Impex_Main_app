<?php

namespace App\Filament\Resources\Settings\AuditCategories;

use App\Domain\SupplierAudits\Models\AuditCategory;
use App\Filament\Resources\Settings\AuditCategories\Pages\CreateAuditCategory;
use App\Filament\Resources\Settings\AuditCategories\Pages\EditAuditCategory;
use App\Filament\Resources\Settings\AuditCategories\Pages\ListAuditCategories;
use App\Filament\Resources\Settings\AuditCategories\Schemas\AuditCategoryForm;
use App\Filament\Resources\Settings\AuditCategories\Tables\AuditCategoriesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class AuditCategoryResource extends Resource
{
    protected static ?string $model = AuditCategory::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?int $navigationSort = 10;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-settings') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return AuditCategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AuditCategoriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditCategories::route('/'),
            'create' => CreateAuditCategory::route('/create'),
            'edit' => EditAuditCategory::route('/{record}/edit'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.resources.audit_categories');
    }
}
