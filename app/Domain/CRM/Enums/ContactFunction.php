<?php

namespace App\Domain\CRM\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ContactFunction: string implements HasLabel, HasColor
{
    case MANAGEMENT = 'management';
    case SALES = 'sales';
    case PURCHASING = 'purchasing';
    case LOGISTICS = 'logistics';
    case FINANCE = 'finance';
    case QUALITY = 'quality';
    case ENGINEERING = 'engineering';
    case OPERATIONS = 'operations';
    case OTHER = 'other';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::MANAGEMENT => 'Management',
            self::SALES => 'Sales',
            self::PURCHASING => 'Purchasing',
            self::LOGISTICS => 'Logistics',
            self::FINANCE => 'Finance',
            self::QUALITY => 'Quality',
            self::ENGINEERING => 'Engineering',
            self::OPERATIONS => 'Operations',
            self::OTHER => 'Other',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::MANAGEMENT => 'danger',
            self::SALES => 'success',
            self::PURCHASING => 'warning',
            self::LOGISTICS => 'info',
            self::FINANCE => 'primary',
            self::QUALITY => 'gray',
            self::ENGINEERING => 'gray',
            self::OPERATIONS => 'info',
            self::OTHER => 'gray',
        };
    }
}
