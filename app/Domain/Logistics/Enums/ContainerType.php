<?php

namespace App\Domain\Logistics\Enums;

use Filament\Support\Contracts\HasLabel;

enum ContainerType: string implements HasLabel
{
    case FCL_20 = '20ft';
    case FCL_40 = '40ft';
    case FCL_40HC = '40hc';
    case FCL_45HC = '45hc';
    case LCL = 'lcl';

    public function getLabel(): string
    {
        return match ($this) {
            self::FCL_20 => "20' FCL",
            self::FCL_40 => "40' FCL",
            self::FCL_40HC => "40' HC",
            self::FCL_45HC => "45' HC",
            self::LCL => 'LCL',
        };
    }
}
