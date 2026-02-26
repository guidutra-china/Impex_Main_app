<?php

namespace App\Filament\Portal\Resources\PaymentResource\Pages;

use App\Filament\Portal\Resources\PaymentResource;
use App\Filament\Portal\Resources\PaymentResource\Widgets\PortalPaymentAllocations;
use Filament\Resources\Pages\ViewRecord;

class ViewPayment extends ViewRecord
{
    protected static string $resource = PaymentResource::class;

    protected function getFooterWidgets(): array
    {
        return [
            PortalPaymentAllocations::class,
        ];
    }
}
