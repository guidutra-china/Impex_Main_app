<?php

namespace App\Filament\Resources\Finance\AccountsPayable;

use App\Domain\Financial\Models\Payment;
use App\Filament\Resources\Finance\AccountsPayable\Pages\CreateAccountsPayable;
use App\Filament\Resources\Finance\AccountsPayable\Pages\EditAccountsPayable;
use App\Filament\Resources\Finance\AccountsPayable\Pages\ListAccountsPayable;
use App\Filament\Resources\Finance\AccountsPayable\Pages\ViewAccountsPayable;
use App\Filament\Resources\Finance\AccountsPayable\Schemas\PayableForm;
use App\Filament\Resources\Finance\AccountsPayable\Schemas\PayableInfolist;
use App\Filament\Resources\Finance\AccountsPayable\Tables\PayablesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AccountsPayableResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-arrow-up-right';

    protected static ?int $navigationSort = 62;

    protected static ?string $slug = 'accounts-payable';

    protected static ?string $recordTitleAttribute = 'reference';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-payments') ?? false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('direction', 'outbound');
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['reference', 'company.name', 'notes'];
    }

    public static function form(Schema $schema): Schema
    {
        return PayableForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PayableInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PayablesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccountsPayable::route('/'),
            'create' => CreateAccountsPayable::route('/create'),
            'view' => ViewAccountsPayable::route('/{record}'),
            'edit' => EditAccountsPayable::route('/{record}/edit'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.finance');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.resources.accounts_payable');
    }

    public static function getModelLabel(): string
    {
        return __('navigation.models.payable');
    }

    public static function getPluralModelLabel(): string
    {
        return __('navigation.models.payables');
    }
}
