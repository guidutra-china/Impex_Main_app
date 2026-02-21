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
        return match ($this) {
            self::MANUAL => 'Manual',
            self::API => 'API',
            self::BANK => 'Bank',
        };
    }
}
