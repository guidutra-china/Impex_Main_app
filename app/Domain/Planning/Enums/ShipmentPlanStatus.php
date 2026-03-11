<?php

namespace App\Domain\Planning\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum ShipmentPlanStatus: string implements HasLabel, HasColor, HasIcon
{
    case DRAFT = 'draft';
    case CONFIRMED = 'confirmed';
    case SHIPPED = 'shipped';
    case CANCELLED = 'cancelled';

    public function getLabel(): ?string
    {
        return __('enums.shipment_plan_status.' . $this->value);
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::CONFIRMED => 'info',
            self::SHIPPED => 'primary',
            self::CANCELLED => 'danger',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::DRAFT => 'heroicon-o-pencil-square',
            self::CONFIRMED => 'heroicon-o-clipboard-document-check',
            self::SHIPPED => 'heroicon-o-truck',
            self::CANCELLED => 'heroicon-o-x-circle',
        };
    }

    public function getEnglishLabel(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::CONFIRMED => 'Confirmed',
            self::SHIPPED => 'Shipped',
            self::CANCELLED => 'Cancelled',
        };
    }
}