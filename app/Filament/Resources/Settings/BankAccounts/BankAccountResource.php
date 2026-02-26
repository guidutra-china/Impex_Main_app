<?php

namespace App\Filament\Resources\Settings\BankAccounts;

use App\Domain\Settings\Models\BankAccount;
use App\Filament\Resources\Settings\BankAccounts\Pages\CreateBankAccount;
use App\Filament\Resources\Settings\BankAccounts\Pages\EditBankAccount;
use App\Filament\Resources\Settings\BankAccounts\Pages\ListBankAccounts;
use App\Filament\Resources\Settings\BankAccounts\Schemas\BankAccountForm;
use App\Filament\Resources\Settings\BankAccounts\Tables\BankAccountsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class BankAccountResource extends Resource
{
    protected static ?string $model = BankAccount::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-building-library';

    protected static ?int $navigationSort = 6;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-settings') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return BankAccountForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BankAccountsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBankAccounts::route('/'),
            'create' => CreateBankAccount::route('/create'),
            'edit' => EditBankAccount::route('/{record}/edit'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.resources.bank_accounts');
    }
}
