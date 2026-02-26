<?php

namespace App\Domain\Inquiries\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum InquirySource: string implements HasLabel, HasColor
{
    case EMAIL = 'email';
    case WHATSAPP = 'whatsapp';
    case PHONE = 'phone';
    case RFQ = 'rfq';
    case WEBSITE = 'website';
    case OTHER = 'other';

    public function getLabel(): ?string
    {
        return __('enums.inquiry_source.' . $this->value);
    }


    public function getColor(): string|array|null
    {
        return match ($this) {
            self::EMAIL => 'info',
            self::WHATSAPP => 'success',
            self::PHONE => 'warning',
            self::RFQ => 'primary',
            self::WEBSITE => 'gray',
            self::OTHER => 'gray',
        };
    }

    public function getEnglishLabel(): string
    {
        return match ($this) {
            self::EMAIL => 'Email',
            self::WHATSAPP => 'WhatsApp',
            self::PHONE => 'Phone',
            self::RFQ => 'Formal RFQ',
            self::WEBSITE => 'Website',
            self::OTHER => 'Other',
        };
    }
}
