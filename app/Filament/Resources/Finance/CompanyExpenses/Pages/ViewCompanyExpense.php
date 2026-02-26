<?php

namespace App\Filament\Resources\Finance\CompanyExpenses\Pages;

use App\Filament\Resources\Finance\CompanyExpenses\CompanyExpenseResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCompanyExpense extends ViewRecord
{
    protected static string $resource = CompanyExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
