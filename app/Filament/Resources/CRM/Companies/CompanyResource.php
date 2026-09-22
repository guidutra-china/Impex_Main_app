<?php

namespace App\Filament\Resources\CRM\Companies;

use App\Domain\CRM\Models\Company;
use App\Filament\Resources\CRM\Companies\Pages\CreateCompany;
use App\Filament\Resources\CRM\Companies\Pages\EditCompany;
use App\Filament\Resources\CRM\Companies\Pages\ListCompanies;
use App\Filament\Resources\CRM\Companies\Pages\ViewCompany;
use App\Filament\Resources\CRM\Companies\RelationManagers\BranchesRelationManager;
use App\Filament\Resources\CRM\Companies\RelationManagers\CategoriesRelationManager;
use App\Filament\Resources\CRM\Companies\RelationManagers\ClientProductsRelationManager;
use App\Filament\Resources\CRM\Companies\RelationManagers\ContactsRelationManager;
use App\Filament\Resources\CRM\Companies\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\CRM\Companies\RelationManagers\RolesRelationManager;
use App\Filament\Resources\CRM\Companies\RelationManagers\SupplierAuditsRelationManager;
use App\Filament\Resources\CRM\Companies\RelationManagers\SupplierProductsRelationManager;
use App\Filament\Resources\CRM\Companies\Schemas\CompanyForm;
use App\Filament\Resources\CRM\Companies\Schemas\CompanyInfolist;
use App\Filament\Resources\CRM\Companies\Tables\CompaniesTable;
use App\Filament\Resources\CRM\Companies\Widgets\CompanyFinancialStatement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?int $navigationSort = 31;

    protected static ?string $slug = 'crm/companies';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-companies') ?? false;
    }

    /**
     * Contatos entram na busca global (Ctrl+K): digitar o nome, e-mail ou
     * telefone de uma pessoa traz a empresa dela — antes só os campos da
     * própria empresa eram pesquisados.
     */
    public static function getGloballySearchableAttributes(): array
    {
        return [
            'name', 'legal_name', 'tax_number', 'email',
            'contacts.name', 'contacts.email', 'contacts.phone', 'contacts.whatsapp', 'contacts.wechat',
        ];
    }

    public static function getGlobalSearchEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with([
            'contacts' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('name'),
        ]);
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(\Illuminate\Database\Eloquent\Model $record): array
    {
        $contact = $record->contacts->first();

        return array_filter([
            __('forms.labels.contact') => $contact
                ? trim($contact->name.($contact->phone ? ' · '.$contact->phone : ''))
                : null,
        ]);
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

    public static function getWidgets(): array
    {
        return [
            CompanyFinancialStatement::class,
        ];
    }

    public static function getRelations(): array
    {
        return [
            BranchesRelationManager::class,
            RolesRelationManager::class,
            ContactsRelationManager::class,
            CategoriesRelationManager::class,
            SupplierProductsRelationManager::class,
            ClientProductsRelationManager::class,
            SupplierAuditsRelationManager::class,
            DocumentsRelationManager::class,
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

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.crm');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.resources.companies');
    }
}
