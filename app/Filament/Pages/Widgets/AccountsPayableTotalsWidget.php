<?php

namespace App\Filament\Pages\Widgets;

use App\Filament\Pages\AccountsPayableOpenItems;

class AccountsPayableTotalsWidget extends OpenItemsTotalsWidget
{
    protected function getTablePage(): string
    {
        return AccountsPayableOpenItems::class;
    }
}
