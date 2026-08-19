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
 * Contas a Pagar — global worklist of OPEN supplier-side schedule items
 * (what Impex owes suppliers), with aging and a "Registrar pagamento" action.
 */
class AccountsPayableOpenItems extends Page implements HasTable
{
    use BuildsOpenItemsTable;
    use InteractsWithTable;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-arrow-trending-down';

    protected static ?int $navigationSort = 64;

    protected static ?string $slug = 'accounts-payable-open';

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
        return __('navigation.pages.accounts_payable_open');
    }

    public function getTitle(): string
    {
        return __('navigation.pages.accounts_payable_open');
    }

    protected function getHeaderWidgets(): array
    {
        return [
            \App\Filament\Pages\Widgets\AccountsPayableTotalsWidget::class,
        ];
    }

    public function table(Table $table): Table
    {
        return $this->openItemsTable($table, PaymentDirection::OUTBOUND);
    }
}
