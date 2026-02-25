<?php

namespace App\Filament\Resources\Settings\AuditCategories\Pages;

use App\Filament\Resources\Settings\AuditCategories\AuditCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAuditCategories extends ListRecords
{
    protected static string $resource = AuditCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
