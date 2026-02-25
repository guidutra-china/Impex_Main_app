<?php

namespace App\Filament\Resources\CRM\SupplierAudits\Pages;

use App\Filament\Resources\CRM\SupplierAudits\SupplierAuditResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSupplierAudit extends CreateRecord
{
    protected static string $resource = SupplierAuditResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
