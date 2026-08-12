<?php

namespace App\Filament\Resources\Settings\PaymentTerms\Pages;

use App\Filament\Pages\Concerns\HasSaveAndReturnFormActions;
use App\Filament\Resources\Settings\PaymentTerms\PaymentTermResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPaymentTerm extends EditRecord
{
    use HasSaveAndReturnFormActions;

    protected static string $resource = PaymentTermResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
