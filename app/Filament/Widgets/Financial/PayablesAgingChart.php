<?php

namespace App\Filament\Widgets\Financial;

use App\Domain\Financial\Queries\AgingBucketsQuery;

class PayablesAgingChart extends AgingChart
{
    protected static ?int $sort = 4;

    public function getHeading(): string
    {
        return __('widgets.financial_dashboard.aging_payables_heading');
    }

    protected function buckets(): array
    {
        return AgingBucketsQuery::payables();
    }
}
