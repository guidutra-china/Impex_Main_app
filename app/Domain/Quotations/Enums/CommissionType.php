<?php

namespace App\Domain\Quotations\Enums;

use Filament\Support\Contracts\HasLabel;

enum CommissionType: string implements HasLabel
{
    case EMBEDDED = 'embedded';
    case SEPARATE = 'separate';

    public function getLabel(): ?string
    {
        return __('enums.commission_type.' . $this->value);
    }


    public function getEnglishLabel(): string
    {
        return match ($this) {
            self::EMBEDDED => 'Embedded in Price',
            self::SEPARATE => 'Separate Line',
        };
    }
}
