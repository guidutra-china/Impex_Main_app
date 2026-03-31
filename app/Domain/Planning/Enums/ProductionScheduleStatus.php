<?php

namespace App\Domain\Planning\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum ProductionScheduleStatus: string implements HasLabel, HasColor, HasIcon
{
    case Draft            = 'draft';
    case PendingApproval  = 'pending_approval';
    case Approved         = 'approved';
    case Rejected         = 'rejected';
    case Completed        = 'completed';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Draft           => 'Draft',
            self::PendingApproval => 'Pending Approval',
            self::Approved        => 'Approved',
            self::Rejected        => 'Rejected',
            self::Completed       => 'Completed',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Draft           => 'gray',
            self::PendingApproval => 'warning',
            self::Approved        => 'success',
            self::Rejected        => 'danger',
            self::Completed       => 'primary',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::Draft           => 'heroicon-o-pencil-square',
            self::PendingApproval => 'heroicon-o-clock',
            self::Approved        => 'heroicon-o-check-circle',
            self::Rejected        => 'heroicon-o-x-circle',
            self::Completed       => 'heroicon-o-check-badge',
        };
    }

    public function canBeEditedBySupplier(): bool
    {
        return in_array($this, [self::Draft, self::Rejected]);
    }
}
