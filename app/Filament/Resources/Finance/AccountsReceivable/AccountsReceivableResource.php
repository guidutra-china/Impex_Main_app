<?php

namespace App\Filament\Resources\Finance\AccountsReceivable;

use App\Domain\Financial\Models\Payment;
use App\Filament\Resources\Finance\AccountsReceivable\Pages\CreateAccountsReceivable;
use App\Filament\Resources\Finance\AccountsReceivable\Pages\EditAccountsReceivable;
use App\Filament\Resources\Finance\AccountsReceivable\Pages\ListAccountsReceivable;
use App\Filament\Resources\Finance\AccountsReceivable\Pages\ReceivablesReport;
use App\Filament\Resources\Finance\AccountsReceivable\Pages\ViewAccountsReceivable;
use App\Filament\Resources\Finance\AccountsReceivable\Schemas\ReceivableForm;
use App\Filament\Resources\Finance\AccountsReceivable\Schemas\ReceivableInfolist;
use App\Filament\Resources\Finance\AccountsReceivable\Tables\ReceivablesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AccountsReceivableResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-arrow-down-left';

    protected static ?int $navigationSort = 61;

    protected static ?string $slug = 'accounts-receivable';

    protected static ?string $recordTitleAttribute = 'reference';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-payments') ?? false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('direction', 'inbound');
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['reference', 'company.name', 'notes'];
    }

    public static function form(Schema $schema): Schema
    {
        return ReceivableForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ReceivableInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ReceivablesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccountsReceivable::route('/'),
            'report' => ReceivablesReport::route('/report'),
            'create' => CreateAccountsReceivable::route('/create'),
            'view' => ViewAccountsReceivable::route('/{record}'),
            'edit' => EditAccountsReceivable::route('/{record}/edit'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.finance');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.resources.accounts_receivable');
    }

    public static function getModelLabel(): string
    {
        return __('navigation.models.receivable');
    }

    public static function getPluralModelLabel(): string
    {
        return __('navigation.models.receivables');
    }
}
