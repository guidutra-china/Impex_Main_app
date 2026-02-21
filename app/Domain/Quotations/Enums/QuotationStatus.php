<?php

namespace App\Domain\Quotations\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum QuotationStatus: string implements HasLabel, HasColor
{
    case DRAFT = 'draft';
    case SENT = 'sent';
    case NEGOTIATING = 'negotiating';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case EXPIRED = 'expired';
    case CANCELLED = 'cancelled';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::SENT => 'Sent',
            self::NEGOTIATING => 'Negotiating',
            self::APPROVED => 'Approved',
            self::REJECTED => 'Rejected',
            self::EXPIRED => 'Expired',
            self::CANCELLED => 'Cancelled',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::SENT => 'info',
            self::NEGOTIATING => 'warning',
            self::APPROVED => 'success',
            self::REJECTED => 'danger',
            self::EXPIRED => 'gray',
            self::CANCELLED => 'danger',
        };
    }
}
