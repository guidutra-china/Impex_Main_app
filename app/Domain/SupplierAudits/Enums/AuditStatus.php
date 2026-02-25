<?php

namespace App\Domain\SupplierAudits\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum AuditStatus: string implements HasLabel, HasColor, HasIcon
{
    case SCHEDULED = 'scheduled';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
    case REVIEWED = 'reviewed';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::SCHEDULED => 'Scheduled',
            self::IN_PROGRESS => 'In Progress',
            self::COMPLETED => 'Completed',
            self::REVIEWED => 'Reviewed',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::SCHEDULED => 'gray',
            self::IN_PROGRESS => 'info',
            self::COMPLETED => 'warning',
            self::REVIEWED => 'success',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::SCHEDULED => 'heroicon-o-calendar',
            self::IN_PROGRESS => 'heroicon-o-pencil-square',
            self::COMPLETED => 'heroicon-o-check-circle',
            self::REVIEWED => 'heroicon-o-shield-check',
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::SCHEDULED => in_array($target, [self::IN_PROGRESS]),
            self::IN_PROGRESS => in_array($target, [self::COMPLETED]),
            self::COMPLETED => in_array($target, [self::REVIEWED]),
            self::REVIEWED => false,
        };
    }
}
