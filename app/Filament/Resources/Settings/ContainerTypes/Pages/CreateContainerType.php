<?php

namespace App\Filament\Resources\Settings\ContainerTypes\Pages;

use App\Filament\Resources\Settings\ContainerTypes\ContainerTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateContainerType extends CreateRecord
{
    protected static string $resource = ContainerTypeResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
