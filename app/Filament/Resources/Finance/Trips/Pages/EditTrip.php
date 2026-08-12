<?php

namespace App\Filament\Resources\Finance\Trips\Pages;

use App\Filament\Pages\Concerns\HasSaveAndReturnFormActions;
use App\Filament\Resources\Finance\Trips\TripResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTrip extends EditRecord
{
    use HasSaveAndReturnFormActions;

    protected static string $resource = TripResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
