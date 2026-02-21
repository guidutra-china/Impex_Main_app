<?php

namespace App\Filament\Resources\Settings\ExchangeRates\Pages;

use App\Filament\Resources\Settings\ExchangeRates\ExchangeRateResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditExchangeRate extends EditRecord
{
    protected static string $resource = ExchangeRateResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (empty($data['inverse_rate']) && !empty($data['rate'])) {
            $data['inverse_rate'] = 1 / (float) $data['rate'];
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
