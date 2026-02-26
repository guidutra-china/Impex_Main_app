<?php

namespace App\Filament\Resources\Finance\CompanyExpenses;

use App\Domain\Finance\Models\CompanyExpense;
use App\Filament\Resources\Finance\CompanyExpenses\Pages\CreateCompanyExpense;
use App\Filament\Resources\Finance\CompanyExpenses\Pages\EditCompanyExpense;
use App\Filament\Resources\Finance\CompanyExpenses\Pages\ListCompanyExpenses;
use App\Filament\Resources\Finance\CompanyExpenses\Pages\ViewCompanyExpense;
use App\Filament\Resources\Finance\CompanyExpenses\Schemas\CompanyExpenseForm;
use App\Filament\Resources\Finance\CompanyExpenses\Schemas\CompanyExpenseInfolist;
use App\Filament\Resources\Finance\CompanyExpenses\Tables\CompanyExpensesTable;
use App\Filament\Resources\Finance\CompanyExpenses\Widgets\MonthlyExpenseSummary;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class CompanyExpenseResource extends Resource
{
    protected static ?string $model = CompanyExpense::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'company-expenses';

    protected static ?string $recordTitleAttribute = 'description';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-company-expenses') ?? false;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['description', 'reference', 'notes'];
    }

    public static function form(Schema $schema): Schema
    {
        return CompanyExpenseForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CompanyExpenseInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CompanyExpensesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompanyExpenses::route('/'),
            'create' => CreateCompanyExpense::route('/create'),
            'view' => ViewCompanyExpense::route('/{record}'),
            'edit' => EditCompanyExpense::route('/{record}/edit'),
        ];
    }

    public static function getWidgets(): array
    {
        return [
            MonthlyExpenseSummary::class,
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.finance');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.resources.company_expenses');
    }

    public static function getModelLabel(): string
    {
        return __('navigation.models.company_expense');
    }

    public static function getPluralModelLabel(): string
    {
        return __('navigation.models.company_expenses');
    }
}
