<?php

namespace App\Domain\Financial\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum PaymentDirection: string implements HasLabel, HasColor, HasIcon
{
    case INBOUND = 'inbound';
    case OUTBOUND = 'outbound';

    public function getLabel(): ?string
    {
        return __('enums.payment_direction.' . $this->value);
    }


    public function getColor(): string|array|null
    {
        return match ($this) {
            self::INBOUND => 'success',
            self::OUTBOUND => 'danger',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::INBOUND => 'heroicon-o-arrow-down-left',
            self::OUTBOUND => 'heroicon-o-arrow-up-right',
        };
    }

    public function getEnglishLabel(): string
    {
        return match ($this) {
            self::INBOUND => 'Inbound (from Client)',
            self::OUTBOUND => 'Outbound (to Supplier)',
        };
    }
}
