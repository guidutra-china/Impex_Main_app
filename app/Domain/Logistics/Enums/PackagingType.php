<?php

namespace App\Domain\Logistics\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum PackagingType: string implements HasLabel, HasColor, HasIcon
{
    case CARTON = 'carton';
    case BAG = 'bag';
    case DRUM = 'drum';
    case WOOD_BOX = 'wood_box';
    case BULK = 'bulk';

    public function getLabel(): ?string
    {
        return __('enums.packaging_type.' . $this->value);
    }


    public function getColor(): string|array|null
    {
        return match ($this) {
            self::CARTON => 'info',
            self::BAG => 'success',
            self::DRUM => 'warning',
            self::WOOD_BOX => 'primary',
            self::BULK => 'gray',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::CARTON => 'heroicon-o-cube',
            self::BAG => 'heroicon-o-shopping-bag',
            self::DRUM => 'heroicon-o-circle-stack',
            self::WOOD_BOX => 'heroicon-o-archive-box',
            self::BULK => 'heroicon-o-squares-2x2',
        };
    }

    public function getEnglishLabel(): string
    {
        return match ($this) {
            self::CARTON => 'Carton',
            self::BAG => 'Bag',
            self::DRUM => 'Drum',
            self::WOOD_BOX => 'Wood Box',
            self::BULK => 'Bulk',
        };
    }
}
