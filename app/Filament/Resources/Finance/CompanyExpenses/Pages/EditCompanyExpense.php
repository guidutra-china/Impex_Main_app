<?php

namespace App\Filament\Resources\Finance\CompanyExpenses\Pages;

use App\Filament\Pages\Concerns\HasSaveAndReturnFormActions;
use App\Filament\Resources\Finance\CompanyExpenses\CompanyExpenseResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCompanyExpense extends EditRecord
{
    use HasSaveAndReturnFormActions;

    protected static string $resource = CompanyExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
