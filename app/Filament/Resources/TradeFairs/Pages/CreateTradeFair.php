<?php

namespace App\Filament\Resources\TradeFairs\Pages;

use App\Filament\Resources\TradeFairs\TradeFairResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTradeFair extends CreateRecord
{
    protected static string $resource = TradeFairResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
