<?php

namespace App\Domain\PurchaseOrders\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum PurchaseOrderStatus: string implements HasLabel, HasColor, HasIcon
{
    case DRAFT = 'draft';
    case SENT = 'sent';
    case CONFIRMED = 'confirmed';
    case IN_PRODUCTION = 'in_production';
    case SHIPPED = 'shipped';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::SENT => 'Sent',
            self::CONFIRMED => 'Confirmed',
            self::IN_PRODUCTION => 'In Production',
            self::SHIPPED => 'Shipped',
            self::COMPLETED => 'Completed',
            self::CANCELLED => 'Cancelled',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::SENT => 'info',
            self::CONFIRMED => 'success',
            self::IN_PRODUCTION => 'warning',
            self::SHIPPED => 'primary',
            self::COMPLETED => 'success',
            self::CANCELLED => 'danger',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::DRAFT => 'heroicon-o-pencil-square',
            self::SENT => 'heroicon-o-paper-airplane',
            self::CONFIRMED => 'heroicon-o-check-circle',
            self::IN_PRODUCTION => 'heroicon-o-cog-6-tooth',
            self::SHIPPED => 'heroicon-o-truck',
            self::COMPLETED => 'heroicon-o-check-badge',
            self::CANCELLED => 'heroicon-o-x-circle',
        };
    }
}
