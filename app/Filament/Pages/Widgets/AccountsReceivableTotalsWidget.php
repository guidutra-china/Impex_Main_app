<?php

namespace App\Filament\Pages\Widgets;

use App\Domain\Financial\Queries\OpenScheduleItemsQuery;
use Illuminate\Database\Eloquent\Builder;

class AccountsReceivableTotalsWidget extends OpenItemsTotalsWidget
{
    protected function openItemsQuery(): Builder
    {
        return OpenScheduleItemsQuery::receivables();
    }
}
