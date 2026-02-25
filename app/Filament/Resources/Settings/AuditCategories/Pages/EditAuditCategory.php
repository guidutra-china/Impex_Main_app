<?php

namespace App\Filament\Resources\Settings\AuditCategories\Pages;

use App\Filament\Resources\Settings\AuditCategories\AuditCategoryResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAuditCategory extends EditRecord
{
    protected static string $resource = AuditCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
