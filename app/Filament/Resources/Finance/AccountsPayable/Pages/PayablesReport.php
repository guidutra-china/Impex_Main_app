<?php

namespace App\Filament\Resources\Finance\AccountsPayable\Pages;

use App\Domain\Financial\Enums\PaymentDirection;
use App\Filament\Resources\Finance\AccountsPayable\AccountsPayableResource;
use App\Filament\Resources\Finance\Concerns\PaymentsSummaryReportPage;

class PayablesReport extends PaymentsSummaryReportPage
{
    protected static string $resource = AccountsPayableResource::class;

    public function direction(): PaymentDirection
    {
        return PaymentDirection::OUTBOUND;
    }

    public function getTitle(): string
    {
        return __('payments_summary_report.title.payable');
    }

    public function getHeading(): string
    {
        return __('payments_summary_report.title.payable');
    }
}
