<?php

namespace App\Domain\Logistics\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum TransportMode: string implements HasLabel, HasColor, HasIcon
{
    case SEA = 'sea';
    case AIR = 'air';
    case LAND = 'land';
    case RAIL = 'rail';
    case MULTIMODAL = 'multimodal';

    public function getLabel(): string
    {
        return match ($this) {
            self::SEA => 'Sea Freight',
            self::AIR => 'Air Freight',
            self::LAND => 'Land Freight',
            self::RAIL => 'Rail Freight',
            self::MULTIMODAL => 'Multimodal',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::SEA => 'info',
            self::AIR => 'warning',
            self::LAND => 'success',
            self::RAIL => 'primary',
            self::MULTIMODAL => 'gray',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::SEA => 'heroicon-o-globe-americas',
            self::AIR => 'heroicon-o-paper-airplane',
            self::LAND => 'heroicon-o-truck',
            self::RAIL => 'heroicon-o-arrow-long-right',
            self::MULTIMODAL => 'heroicon-o-arrows-right-left',
        };
    }
}
