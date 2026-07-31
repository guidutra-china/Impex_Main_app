<?php

namespace App\Filament\Widgets\Financial;

use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Queries\OpenScheduleItemsQuery;
use Illuminate\Database\Eloquent\Builder;

class UpcomingReceivablesTable extends UpcomingScheduleItemsTable
{
    protected static ?int $sort = 5;

    protected function tableHeading(): string
    {
        return __('widgets.financial_dashboard.upcoming_receivables_heading');
    }

    protected function direction(): PaymentDirection
    {
        return PaymentDirection::INBOUND;
    }

    protected function openItemsQuery(): Builder
    {
        return OpenScheduleItemsQuery::receivables();
    }
}
