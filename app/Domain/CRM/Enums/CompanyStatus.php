<?php

namespace App\Domain\CRM\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CompanyStatus: string implements HasLabel, HasColor
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
    case PROSPECT = 'prospect';
    case BLOCKED = 'blocked';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::ACTIVE => 'Active',
            self::INACTIVE => 'Inactive',
            self::PROSPECT => 'Prospect',
            self::BLOCKED => 'Blocked',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::ACTIVE => 'success',
            self::INACTIVE => 'gray',
            self::PROSPECT => 'info',
            self::BLOCKED => 'danger',
        };
    }
}
