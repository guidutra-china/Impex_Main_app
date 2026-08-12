<?php

namespace App\Filament\Resources\Settings\BankAccounts\Pages;

use App\Filament\Pages\Concerns\HasSaveAndReturnFormActions;
use App\Filament\Resources\Settings\BankAccounts\BankAccountResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBankAccount extends EditRecord
{
    use HasSaveAndReturnFormActions;

    protected static string $resource = BankAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
