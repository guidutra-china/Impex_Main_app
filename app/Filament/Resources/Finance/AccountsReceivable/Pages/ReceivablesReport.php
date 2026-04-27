<?php

namespace App\Filament\Resources\Finance\AccountsReceivable\Pages;

use App\Domain\Financial\Enums\PaymentDirection;
use App\Filament\Resources\Finance\AccountsReceivable\AccountsReceivableResource;
use App\Filament\Resources\Finance\Concerns\PaymentsSummaryReportPage;

class ReceivablesReport extends PaymentsSummaryReportPage
{
    protected static string $resource = AccountsReceivableResource::class;

    public function direction(): PaymentDirection
    {
        return PaymentDirection::INBOUND;
    }

    public function getTitle(): string
    {
        return __('payments_summary_report.title.receivable');
    }

    public function getHeading(): string
    {
        return __('payments_summary_report.title.receivable');
    }
}
