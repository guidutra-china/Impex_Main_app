<?php

namespace App\Domain\SupplierAudits\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum AuditStatus: string implements HasLabel, HasColor, HasIcon
{
    case SCHEDULED = 'scheduled';
    case IN_PROGRESS = 'in_progress';
    case UNDER_REVIEW = 'under_review';
    case COMPLETED = 'completed';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::SCHEDULED => 'Scheduled',
            self::IN_PROGRESS => 'In Progress',
            self::UNDER_REVIEW => 'Under Review',
            self::COMPLETED => 'Completed',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::SCHEDULED => 'gray',
            self::IN_PROGRESS => 'info',
            self::UNDER_REVIEW => 'warning',
            self::COMPLETED => 'success',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::SCHEDULED => 'heroicon-o-calendar',
            self::IN_PROGRESS => 'heroicon-o-pencil-square',
            self::UNDER_REVIEW => 'heroicon-o-eye',
            self::COMPLETED => 'heroicon-o-check-circle',
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::SCHEDULED => in_array($target, [self::IN_PROGRESS]),
            self::IN_PROGRESS => in_array($target, [self::UNDER_REVIEW]),
            self::UNDER_REVIEW => in_array($target, [self::COMPLETED, self::IN_PROGRESS]),
            self::COMPLETED => false,
        };
    }
}
