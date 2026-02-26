<?php

namespace App\Domain\Settings\Enums;

use Filament\Support\Contracts\HasLabel;

enum FeeType: string implements HasLabel
{
    case NONE = 'none';
    case FIXED = 'fixed';
    case PERCENTAGE = 'percentage';
    case FIXED_PLUS_PERCENTAGE = 'fixed_plus_percentage';

    public function getLabel(): ?string
    {
        return __('enums.fee_type.' . $this->value);
    }


    public function getEnglishLabel(): string
    {
        return match ($this) {
            self::NONE => 'No Fee',
            self::FIXED => 'Fixed Fee',
            self::PERCENTAGE => 'Percentage Fee',
            self::FIXED_PLUS_PERCENTAGE => 'Fixed + Percentage',
        };
    }
}
