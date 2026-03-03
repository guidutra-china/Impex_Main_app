<?php

namespace App\Filament\SupplierPortal\Resources\PaymentResource\Pages;

use App\Filament\SupplierPortal\Resources\PaymentResource;
use Filament\Resources\Pages\ViewRecord;

class ViewPayment extends ViewRecord
{
    protected static string $resource = PaymentResource::class;

    protected function getFooterWidgets(): array
    {
        return [
            PaymentResource\Widgets\SupplierPaymentAllocations::class,
        ];
    }
}
