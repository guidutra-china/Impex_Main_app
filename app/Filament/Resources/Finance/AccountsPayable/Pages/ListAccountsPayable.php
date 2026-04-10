<?php

namespace App\Filament\Resources\Finance\AccountsPayable\Pages;

use App\Filament\Resources\Finance\AccountsPayable\AccountsPayableResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAccountsPayable extends ListRecords
{
    protected static string $resource = AccountsPayableResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
