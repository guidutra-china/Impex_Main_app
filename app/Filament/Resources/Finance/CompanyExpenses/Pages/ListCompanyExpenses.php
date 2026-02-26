<?php

namespace App\Filament\Resources\Finance\CompanyExpenses\Pages;

use App\Filament\Resources\Finance\CompanyExpenses\CompanyExpenseResource;
use App\Filament\Resources\Finance\CompanyExpenses\Widgets\MonthlyExpenseSummary;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCompanyExpenses extends ListRecords
{
    protected static string $resource = CompanyExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            MonthlyExpenseSummary::class,
        ];
    }
}
