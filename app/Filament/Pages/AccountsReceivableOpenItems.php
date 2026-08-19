<?php

namespace App\Filament\Pages;

use App\Domain\Financial\Enums\PaymentDirection;
use App\Filament\Pages\Concerns\BuildsOpenItemsTable;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Contas a Receber — global worklist of OPEN client-side schedule items
 * (what clients owe Impex), with aging and a "Registrar pagamento" action.
 */
class AccountsReceivableOpenItems extends Page implements HasTable
{
    use BuildsOpenItemsTable;
    use InteractsWithTable;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-arrow-trending-up';

    protected static ?int $navigationSort = 62;

    protected static ?string $slug = 'accounts-receivable-open';

    protected string $view = 'filament.pages.open-items';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-payments') ?? false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.finance');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.pages.accounts_receivable_open');
    }

    public function getTitle(): string
    {
        return __('navigation.pages.accounts_receivable_open');
    }

    protected function getHeaderWidgets(): array
    {
        return [
            \App\Filament\Pages\Widgets\AccountsReceivableTotalsWidget::class,
        ];
    }

    public function table(Table $table): Table
    {
        return $this->openItemsTable($table, PaymentDirection::INBOUND);
    }
}
