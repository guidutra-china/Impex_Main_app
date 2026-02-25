<?php

namespace App\Domain\SupplierAudits\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AuditType: string implements HasLabel, HasColor
{
    case INITIAL = 'initial';
    case PERIODIC = 'periodic';
    case REQUALIFICATION = 'requalification';
    case FOR_CAUSE = 'for_cause';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::INITIAL => 'Initial Qualification',
            self::PERIODIC => 'Periodic Review',
            self::REQUALIFICATION => 'Re-qualification',
            self::FOR_CAUSE => 'For Cause',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::INITIAL => 'info',
            self::PERIODIC => 'gray',
            self::REQUALIFICATION => 'warning',
            self::FOR_CAUSE => 'danger',
        };
    }
}
