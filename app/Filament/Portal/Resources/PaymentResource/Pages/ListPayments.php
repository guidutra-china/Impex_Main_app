<?php

namespace App\Filament\Portal\Resources\PaymentResource\Pages;

use App\Filament\Portal\Resources\PaymentResource;
use App\Filament\Portal\Widgets\PaymentsListStats;
use Filament\Resources\Pages\ListRecords;

class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            PaymentsListStats::class,
        ];
    }
}
