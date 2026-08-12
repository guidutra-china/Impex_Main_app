<?php

namespace App\Filament\Resources\TradeFairs\Pages;

use App\Filament\Pages\Concerns\HasSaveAndReturnFormActions;
use App\Filament\Resources\TradeFairs\TradeFairResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTradeFair extends EditRecord
{
    use HasSaveAndReturnFormActions;

    protected static string $resource = TradeFairResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
