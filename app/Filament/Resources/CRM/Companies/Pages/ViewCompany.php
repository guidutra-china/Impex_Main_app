<?php

namespace App\Filament\Resources\CRM\Companies\Pages;

use App\Filament\Resources\CRM\Companies\CompanyResource;
use App\Filament\Resources\CRM\Companies\Widgets\CompanyFinancialStatement;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCompany extends ViewRecord
{
    protected static string $resource = CompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            CompanyFinancialStatement::class,
        ];
    }
}
