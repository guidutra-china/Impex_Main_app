<?php

namespace App\Filament\Resources\Audit\Pages;

use App\Filament\Resources\Audit\AuditLogResource;
use Filament\Resources\Pages\ListRecords;

class ListAuditLogs extends ListRecords
{
    protected static string $resource = AuditLogResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
