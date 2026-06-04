<?php

namespace App\Domain\Travel\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum TravelExpenseCategory: string implements HasColor, HasIcon, HasLabel
{
    case TRANSPORT = 'transport';
    case LODGING = 'lodging';
    case MEALS = 'meals';
    case VISA_FEES = 'visa_fees';
    case TELECOM = 'telecom';
    case SAMPLES = 'samples';
    case ENTERTAINMENT = 'entertainment';
    case OTHER = 'other';

    public function getLabel(): ?string
    {
        return __('enums.travel_expense_category.'.$this->value);
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::TRANSPORT => 'info',
            self::LODGING => 'primary',
            self::MEALS => 'warning',
            self::VISA_FEES => 'danger',
            self::TELECOM => 'info',
            self::SAMPLES => 'success',
            self::ENTERTAINMENT => 'gray',
            self::OTHER => 'gray',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::TRANSPORT => 'heroicon-o-paper-airplane',
            self::LODGING => 'heroicon-o-home-modern',
            self::MEALS => 'heroicon-o-cake',
            self::VISA_FEES => 'heroicon-o-identification',
            self::TELECOM => 'heroicon-o-phone',
            self::SAMPLES => 'heroicon-o-cube',
            self::ENTERTAINMENT => 'heroicon-o-sparkles',
            self::OTHER => 'heroicon-o-ellipsis-horizontal-circle',
        };
    }
}
