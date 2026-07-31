<?php

namespace App\Filament\Widgets\Financial;

use App\Domain\Financial\Queries\AgingBucketsQuery;

class ReceivablesAgingChart extends AgingChart
{
    protected static ?int $sort = 3;

    public function getHeading(): string
    {
        return __('widgets.financial_dashboard.aging_receivables_heading');
    }

    protected function buckets(): array
    {
        return AgingBucketsQuery::receivables();
    }
}
