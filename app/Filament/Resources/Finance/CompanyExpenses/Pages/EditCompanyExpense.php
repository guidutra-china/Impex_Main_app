<?php

namespace App\Filament\Resources\Finance\CompanyExpenses\Pages;

use App\Filament\Resources\Finance\CompanyExpenses\CompanyExpenseResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCompanyExpense extends EditRecord
{
    protected static string $resource = CompanyExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
