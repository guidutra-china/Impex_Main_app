<?php

namespace App\Filament\Resources\Settings\PaymentTerms\Pages;

use App\Filament\Resources\Settings\PaymentTerms\PaymentTermResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPaymentTerms extends ListRecords
{
    protected static string $resource = PaymentTermResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
