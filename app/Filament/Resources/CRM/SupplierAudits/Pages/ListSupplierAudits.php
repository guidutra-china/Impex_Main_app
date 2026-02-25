<?php

namespace App\Filament\Resources\CRM\SupplierAudits\Pages;

use App\Filament\Resources\CRM\SupplierAudits\SupplierAuditResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSupplierAudits extends ListRecords
{
    protected static string $resource = SupplierAuditResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
