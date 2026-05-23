<?php

namespace App\Filament\Resources\TradeFairs\Pages;

use App\Filament\Resources\TradeFairs\TradeFairResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewTradeFair extends ViewRecord
{
    protected static string $resource = TradeFairResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
