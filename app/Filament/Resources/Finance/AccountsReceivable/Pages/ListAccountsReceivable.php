<?php

namespace App\Filament\Resources\Finance\AccountsReceivable\Pages;

use App\Filament\Resources\Finance\AccountsReceivable\AccountsReceivableResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAccountsReceivable extends ListRecords
{
    protected static string $resource = AccountsReceivableResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
