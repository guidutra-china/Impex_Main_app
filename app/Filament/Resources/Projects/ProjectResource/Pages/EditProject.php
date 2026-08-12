<?php

namespace App\Filament\Resources\Projects\ProjectResource\Pages;

use App\Filament\Pages\Concerns\HasSaveAndReturnFormActions;
use App\Filament\Resources\Projects\ProjectResource;
use Filament\Resources\Pages\EditRecord;

class EditProject extends EditRecord
{
    use HasSaveAndReturnFormActions;

    protected static string $resource = ProjectResource::class;
}
