<?php

namespace App\Filament\Resources\CRM\SupplierAudits\Pages;

use App\Filament\Resources\CRM\SupplierAudits\SupplierAuditResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditSupplierAudit extends EditRecord
{
    protected static string $resource = SupplierAuditResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
