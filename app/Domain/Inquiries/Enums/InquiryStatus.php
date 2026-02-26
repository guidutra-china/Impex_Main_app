<?php

namespace App\Domain\Inquiries\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum InquiryStatus: string implements HasLabel, HasColor
{
    case RECEIVED = 'received';
    case QUOTING = 'quoting';
    case QUOTED = 'quoted';
    case WON = 'won';
    case LOST = 'lost';
    case CANCELLED = 'cancelled';

    public function getLabel(): ?string
    {
        return __('enums.inquiry_status.' . $this->value);
    }


    public function getColor(): string|array|null
    {
        return match ($this) {
            self::RECEIVED => 'info',
            self::QUOTING => 'warning',
            self::QUOTED => 'primary',
            self::WON => 'success',
            self::LOST => 'danger',
            self::CANCELLED => 'gray',
        };
    }

    public function getEnglishLabel(): string
    {
        return match ($this) {
            self::RECEIVED => 'Received',
            self::QUOTING => 'Quoting',
            self::QUOTED => 'Quoted',
            self::WON => 'Won',
            self::LOST => 'Lost',
            self::CANCELLED => 'Cancelled',
        };
    }
}
