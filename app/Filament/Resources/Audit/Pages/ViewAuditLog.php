<?php

namespace App\Filament\Resources\Audit\Pages;

use App\Filament\Resources\Audit\AuditLogResource;
use Filament\Resources\Pages\ViewRecord;

class ViewAuditLog extends ViewRecord
{
    protected static string $resource = AuditLogResource::class;
}
