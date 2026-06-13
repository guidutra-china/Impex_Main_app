<?php

namespace App\Domain\Financial\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum CreditNoteStatus: string implements HasColor, HasIcon, HasLabel
{
    case DRAFT = 'draft';
    case ISSUED = 'issued';
    case PARTIALLY_APPLIED = 'partially_applied';
    case APPLIED = 'applied';
    case REFUNDED = 'refunded';
    case CANCELLED = 'cancelled';

    public function getLabel(): ?string
    {
        return __('enums.credit_note_status.'.$this->value);
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::ISSUED => 'info',
            self::PARTIALLY_APPLIED => 'warning',
            self::APPLIED => 'success',
            self::REFUNDED => 'success',
            self::CANCELLED => 'danger',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::DRAFT => 'heroicon-o-pencil-square',
            self::ISSUED => 'heroicon-o-paper-airplane',
            self::PARTIALLY_APPLIED => 'heroicon-o-clock',
            self::APPLIED => 'heroicon-o-check-circle',
            self::REFUNDED => 'heroicon-o-banknotes',
            self::CANCELLED => 'heroicon-o-x-circle',
        };
    }
}
