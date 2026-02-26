<?php

namespace App\Filament\Resources\Finance\CompanyExpenses\Pages;

use App\Filament\Resources\Finance\CompanyExpenses\CompanyExpenseResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCompanyExpense extends CreateRecord
{
    protected static string $resource = CompanyExpenseResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
