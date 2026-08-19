<?php

namespace App\Filament\Pages\Widgets;

use App\Filament\Pages\AccountsReceivableOpenItems;

class AccountsReceivableTotalsWidget extends OpenItemsTotalsWidget
{
    protected function getTablePage(): string
    {
        return AccountsReceivableOpenItems::class;
    }
}
