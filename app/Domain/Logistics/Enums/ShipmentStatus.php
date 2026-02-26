<?php

namespace App\Domain\Logistics\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum ShipmentStatus: string implements HasLabel, HasColor, HasIcon
{
    case DRAFT = 'draft';
    case BOOKED = 'booked';
    case CUSTOMS = 'customs';
    case IN_TRANSIT = 'in_transit';
    case ARRIVED = 'arrived';
    case CANCELLED = 'cancelled';

    public function getLabel(): ?string
    {
        return __('enums.shipment_status.' . $this->value);
    }


    public function getColor(): string|array|null
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::BOOKED => 'info',
            self::CUSTOMS => 'warning',
            self::IN_TRANSIT => 'primary',
            self::ARRIVED => 'success',
            self::CANCELLED => 'danger',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::DRAFT => 'heroicon-o-pencil-square',
            self::BOOKED => 'heroicon-o-clipboard-document-check',
            self::CUSTOMS => 'heroicon-o-shield-check',
            self::IN_TRANSIT => 'heroicon-o-truck',
            self::ARRIVED => 'heroicon-o-check-badge',
            self::CANCELLED => 'heroicon-o-x-circle',
        };
    }

    public function getEnglishLabel(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::BOOKED => 'Booked',
            self::CUSTOMS => 'Customs',
            self::IN_TRANSIT => 'In Transit',
            self::ARRIVED => 'Arrived',
            self::CANCELLED => 'Cancelled',
        };
    }
}
