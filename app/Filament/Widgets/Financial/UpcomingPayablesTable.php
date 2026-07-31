<?php

namespace App\Filament\Widgets\Financial;

use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Queries\OpenScheduleItemsQuery;
use Illuminate\Database\Eloquent\Builder;

class UpcomingPayablesTable extends UpcomingScheduleItemsTable
{
    protected static ?int $sort = 6;

    protected function tableHeading(): string
    {
        return __('widgets.financial_dashboard.upcoming_payables_heading');
    }

    protected function direction(): PaymentDirection
    {
        return PaymentDirection::OUTBOUND;
    }

    protected function openItemsQuery(): Builder
    {
        return OpenScheduleItemsQuery::payables();
    }
}
