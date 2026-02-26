<?php

namespace App\Domain\Financial\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum PaymentScheduleStatus: string implements HasLabel, HasColor, HasIcon
{
    case PENDING = 'pending';
    case DUE = 'due';
    case PAID = 'paid';
    case OVERDUE = 'overdue';
    case WAIVED = 'waived';

    public function getLabel(): ?string
    {
        return __('enums.payment_schedule_status.' . $this->value);
    }


    public function getColor(): string|array|null
    {
        return match ($this) {
            self::PENDING => 'gray',
            self::DUE => 'warning',
            self::PAID => 'success',
            self::OVERDUE => 'danger',
            self::WAIVED => 'info',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::PENDING => 'heroicon-o-clock',
            self::DUE => 'heroicon-o-exclamation-triangle',
            self::PAID => 'heroicon-o-check-circle',
            self::OVERDUE => 'heroicon-o-x-circle',
            self::WAIVED => 'heroicon-o-arrow-uturn-right',
        };
    }

    public function isResolved(): bool
    {
        return in_array($this, [self::PAID, self::WAIVED]);
    }

    public function getEnglishLabel(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::DUE => 'Due',
            self::PAID => 'Paid',
            self::OVERDUE => 'Overdue',
            self::WAIVED => 'Waived',
        };
    }
}
