<?php

namespace App\Filament\Resources\Settings\ContainerTypes\Pages;

use App\Filament\Pages\Concerns\HasSaveAndReturnFormActions;
use App\Filament\Resources\Settings\ContainerTypes\ContainerTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditContainerType extends EditRecord
{
    use HasSaveAndReturnFormActions;

    protected static string $resource = ContainerTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
