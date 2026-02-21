<?php

namespace App\Filament\Resources\Catalog\Tags\Pages;

use App\Filament\Resources\Catalog\Tags\TagResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTag extends CreateRecord
{
    protected static string $resource = TagResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
