<?php

namespace App\Filament\Resources\Settings\PaymentMethods\Pages;

use App\Filament\Pages\Concerns\HasSaveAndReturnFormActions;
use App\Filament\Resources\Settings\PaymentMethods\PaymentMethodResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPaymentMethod extends EditRecord
{
    use HasSaveAndReturnFormActions;

    protected static string $resource = PaymentMethodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
