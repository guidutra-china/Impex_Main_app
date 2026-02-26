<?php

namespace App\Domain\Settings\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum BankAccountStatus: string implements HasLabel, HasColor
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
    case CLOSED = 'closed';

    public function getLabel(): ?string
    {
        return __('enums.bank_account_status.' . $this->value);
    }


    public function getColor(): string|array|null
    {
        return match ($this) {
            self::ACTIVE => 'success',
            self::INACTIVE => 'warning',
            self::CLOSED => 'danger',
        };
    }

    public function getEnglishLabel(): string
    {
        return match ($this) {
            self::ACTIVE => 'Active',
            self::INACTIVE => 'Inactive',
            self::CLOSED => 'Closed',
        };
    }
}
