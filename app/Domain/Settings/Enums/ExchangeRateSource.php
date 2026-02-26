<?php

namespace App\Domain\Settings\Enums;

use Filament\Support\Contracts\HasLabel;

enum ExchangeRateSource: string implements HasLabel
{
    case MANUAL = 'manual';
    case API = 'api';
    case BANK = 'bank';

    public function getLabel(): ?string
    {
        return __('enums.exchange_rate_source.' . $this->value);
    }


    public function getEnglishLabel(): string
    {
        return match ($this) {
            self::MANUAL => 'Manual',
            self::API => 'API',
            self::BANK => 'Bank',
        };
    }
}
