<?php

namespace App\Filament\Resources\Projects\ProjectTemplateResource\Pages;

use App\Filament\Pages\Concerns\HasSaveAndReturnFormActions;
use App\Filament\Resources\Projects\ProjectTemplateResource;
use Filament\Resources\Pages\EditRecord;

class EditProjectTemplate extends EditRecord
{
    use HasSaveAndReturnFormActions;

    protected static string $resource = ProjectTemplateResource::class;
}
