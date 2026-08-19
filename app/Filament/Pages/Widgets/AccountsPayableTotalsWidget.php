<?php

namespace App\Filament\Pages\Widgets;

use App\Domain\Financial\Queries\OpenScheduleItemsQuery;
use Illuminate\Database\Eloquent\Builder;

class AccountsPayableTotalsWidget extends OpenItemsTotalsWidget
{
    protected function openItemsQuery(): Builder
    {
        return OpenScheduleItemsQuery::payables();
    }
}
