<?php

namespace App\Filament\Resources\Settings\ExchangeRates\Pages;

use App\Filament\Resources\Settings\ExchangeRates\ExchangeRateResource;
use Filament\Resources\Pages\CreateRecord;

class CreateExchangeRate extends CreateRecord
{
    protected static string $resource = ExchangeRateResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        if (empty($data['inverse_rate']) && !empty($data['rate'])) {
            $data['inverse_rate'] = 1 / (float) $data['rate'];
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
