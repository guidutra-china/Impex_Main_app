<?php

namespace App\Domain\SupplierAudits\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum AuditResult: string implements HasLabel, HasColor, HasIcon
{
    case APPROVED = 'approved';
    case CONDITIONAL = 'conditional';
    case REJECTED = 'rejected';

    public function getLabel(): ?string
    {
        return __('enums.audit_result.' . $this->value);
    }


    public function getColor(): string|array|null
    {
        return match ($this) {
            self::APPROVED => 'success',
            self::CONDITIONAL => 'warning',
            self::REJECTED => 'danger',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::APPROVED => 'heroicon-o-check-circle',
            self::CONDITIONAL => 'heroicon-o-exclamation-triangle',
            self::REJECTED => 'heroicon-o-x-circle',
        };
    }

    public function getEnglishLabel(): string
    {
        return match ($this) {
            self::APPROVED => 'Approved',
            self::CONDITIONAL => 'Conditional',
            self::REJECTED => 'Rejected',
        };
    }
}
