<?php

namespace App\Filament\Resources\CRM\Companies;

use App\Domain\CRM\Models\Company;
use App\Filament\Resources\CRM\Companies\Pages\CreateCompany;
use App\Filament\Resources\CRM\Companies\Pages\EditCompany;
use App\Filament\Resources\CRM\Companies\Pages\ListCompanies;
use App\Filament\Resources\CRM\Companies\Pages\ViewCompany;
use App\Filament\Resources\CRM\Companies\RelationManagers\CategoriesRelationManager;
use App\Filament\Resources\CRM\Companies\RelationManagers\ClientProductsRelationManager;
use App\Filament\Resources\CRM\Companies\RelationManagers\ContactsRelationManager;
use App\Filament\Resources\CRM\Companies\RelationManagers\RolesRelationManager;
use App\Filament\Resources\CRM\Companies\RelationManagers\SupplierAuditsRelationManager;
use App\Filament\Resources\CRM\Companies\RelationManagers\SupplierProductsRelationManager;
use App\Filament\Resources\CRM\Companies\Schemas\CompanyForm;
use App\Filament\Resources\CRM\Companies\Schemas\CompanyInfolist;
use App\Filament\Resources\CRM\Companies\Tables\CompaniesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static UnitEnum|string|null $navigationGroup = 'CRM';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Companies';

    protected static ?string $slug = 'crm/companies';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-companies') ?? false;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'legal_name', 'tax_number', 'email'];
    }

    public static function form(Schema $schema): Schema
    {
        return CompanyForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CompanyInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CompaniesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RolesRelationManager::class,
            ContactsRelationManager::class,
            CategoriesRelationManager::class,
            SupplierProductsRelationManager::class,
            ClientProductsRelationManager::class,
            SupplierAuditsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompanies::route('/'),
            'create' => CreateCompany::route('/create'),
            'view' => ViewCompany::route('/{record}'),
            'edit' => EditCompany::route('/{record}/edit'),
        ];
    }
}
