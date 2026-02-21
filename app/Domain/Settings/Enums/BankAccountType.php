<?php

namespace App\Domain\Settings\Enums;

use Filament\Support\Contracts\HasLabel;

enum BankAccountType: string implements HasLabel
{
    case CHECKING = 'checking';
    case SAVINGS = 'savings';
    case BUSINESS = 'business';
    case ESCROW = 'escrow';
    case FOREIGN_CURRENCY = 'foreign_currency';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::CHECKING => 'Checking',
            self::SAVINGS => 'Savings',
            self::BUSINESS => 'Business',
            self::ESCROW => 'Escrow',
            self::FOREIGN_CURRENCY => 'Foreign Currency',
        };
    }
}
