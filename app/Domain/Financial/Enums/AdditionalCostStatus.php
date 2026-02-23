<?php

namespace App\Domain\Financial\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AdditionalCostStatus: string implements HasLabel, HasColor
{
    case PENDING = 'pending';
    case INVOICED = 'invoiced';
    case PAID = 'paid';
    case WAIVED = 'waived';

    public function getLabel(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::INVOICED => 'Invoiced',
            self::PAID => 'Paid',
            self::WAIVED => 'Waived',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::PENDING => 'warning',
            self::INVOICED => 'info',
            self::PAID => 'success',
            self::WAIVED => 'gray',
        };
    }
}
