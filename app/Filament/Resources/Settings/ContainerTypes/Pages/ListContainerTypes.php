<?php

namespace App\Filament\Resources\Settings\ContainerTypes\Pages;

use App\Filament\Resources\Settings\ContainerTypes\ContainerTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListContainerTypes extends ListRecords
{
    protected static string $resource = ContainerTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
