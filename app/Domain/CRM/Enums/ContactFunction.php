<?php

namespace App\Domain\CRM\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ContactFunction: string implements HasLabel, HasColor
{
    case CEO = 'ceo';
    case CTO = 'cto';
    case CFO = 'cfo';
    case DIRECTOR = 'director';
    case MANAGER = 'manager';
    case SALES_MANAGER = 'sales_manager';
    case SALES = 'sales';
    case SUPERVISOR = 'supervisor';
    case COORDINATOR = 'coordinator';
    case ANALYST = 'analyst';
    case SPECIALIST = 'specialist';
    case CONSULTANT = 'consultant';
    case PURCHASING = 'purchasing';
    case LOGISTICS = 'logistics';
    case OTHER = 'other';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::CEO => 'CEO',
            self::CTO => 'CTO',
            self::CFO => 'CFO',
            self::DIRECTOR => 'Director',
            self::MANAGER => 'Manager',
            self::SALES_MANAGER => 'Sales Manager',
            self::SALES => 'Sales',
            self::SUPERVISOR => 'Supervisor',
            self::COORDINATOR => 'Coordinator',
            self::ANALYST => 'Analyst',
            self::SPECIALIST => 'Specialist',
            self::CONSULTANT => 'Consultant',
            self::PURCHASING => 'Purchasing',
            self::LOGISTICS => 'Logistics',
            self::OTHER => 'Other',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::CEO, self::CTO, self::CFO => 'danger',
            self::DIRECTOR => 'warning',
            self::MANAGER, self::SALES_MANAGER => 'success',
            self::SUPERVISOR, self::COORDINATOR => 'info',
            self::SALES, self::PURCHASING, self::LOGISTICS => 'primary',
            default => 'gray',
        };
    }

    public function isExecutive(): bool
    {
        return in_array($this, [self::CEO, self::CTO, self::CFO]);
    }
}
