<?php

namespace App\Filament\Resources\Settings\PaymentTerms\Pages;

use App\Filament\Resources\Settings\PaymentTerms\PaymentTermResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePaymentTerm extends CreateRecord
{
    protected static string $resource = PaymentTermResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
