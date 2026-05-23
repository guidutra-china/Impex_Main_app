<?php

namespace App\Filament\Resources\TradeFairs\Pages;

use App\Filament\Resources\TradeFairs\TradeFairResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTradeFairs extends ListRecords
{
    protected static string $resource = TradeFairResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
