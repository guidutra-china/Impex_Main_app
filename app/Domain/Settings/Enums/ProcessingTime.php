<?php

namespace App\Domain\Settings\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ProcessingTime: string implements HasLabel, HasColor
{
    case IMMEDIATE = 'immediate';
    case SAME_DAY = 'same_day';
    case ONE_TO_THREE_DAYS = '1_3_days';
    case THREE_TO_FIVE_DAYS = '3_5_days';
    case FIVE_TO_SEVEN_DAYS = '5_7_days';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::IMMEDIATE => 'Immediate',
            self::SAME_DAY => 'Same Day',
            self::ONE_TO_THREE_DAYS => '1-3 Days',
            self::THREE_TO_FIVE_DAYS => '3-5 Days',
            self::FIVE_TO_SEVEN_DAYS => '5-7 Days',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::IMMEDIATE => 'success',
            self::SAME_DAY => 'info',
            self::ONE_TO_THREE_DAYS => 'warning',
            self::THREE_TO_FIVE_DAYS => 'gray',
            self::FIVE_TO_SEVEN_DAYS => 'gray',
        };
    }
}
