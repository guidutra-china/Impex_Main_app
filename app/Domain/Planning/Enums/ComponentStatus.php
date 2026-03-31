<?php

namespace App\Domain\Planning\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ComponentStatus: string implements HasLabel, HasColor
{
    case AtFactory  = 'at_factory';
    case InTransit  = 'in_transit';
    case AtSupplier = 'at_supplier';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::AtFactory  => 'At Factory',
            self::InTransit  => 'In Transit',
            self::AtSupplier => 'At Supplier',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::AtFactory  => 'success',
            self::InTransit  => 'warning',
            self::AtSupplier => 'danger',
        };
    }

    public function isRisk(): bool
    {
        return $this !== self::AtFactory;
    }
}
