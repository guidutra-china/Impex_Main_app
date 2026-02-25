<?php

namespace App\Filament\Resources\Settings\AuditCategories\Pages;

use App\Filament\Resources\Settings\AuditCategories\AuditCategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAuditCategory extends CreateRecord
{
    protected static string $resource = AuditCategoryResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
