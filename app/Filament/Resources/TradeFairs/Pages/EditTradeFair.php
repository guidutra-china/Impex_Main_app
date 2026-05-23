<?php

namespace App\Filament\Resources\TradeFairs\Pages;

use App\Filament\Resources\TradeFairs\TradeFairResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTradeFair extends EditRecord
{
    protected static string $resource = TradeFairResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
