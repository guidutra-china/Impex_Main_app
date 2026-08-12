<?php

namespace App\Filament\Resources\CRM\SupplierAudits\Pages;

use App\Filament\Pages\Concerns\HasSaveAndReturnFormActions;
use App\Filament\Resources\CRM\SupplierAudits\SupplierAuditResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditSupplierAudit extends EditRecord
{
    use HasSaveAndReturnFormActions;

    protected static string $resource = SupplierAuditResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
