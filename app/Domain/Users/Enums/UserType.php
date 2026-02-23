<?php

namespace App\Domain\Users\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum UserType: string implements HasLabel, HasColor, HasIcon
{
    case INTERNAL = 'internal';
    case CLIENT = 'client';
    case SUPPLIER = 'supplier';

    public function getLabel(): string
    {
        return match ($this) {
            self::INTERNAL => 'Internal',
            self::CLIENT => 'Client',
            self::SUPPLIER => 'Supplier',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::INTERNAL => 'primary',
            self::CLIENT => 'success',
            self::SUPPLIER => 'warning',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::INTERNAL => 'heroicon-o-building-office',
            self::CLIENT => 'heroicon-o-user-group',
            self::SUPPLIER => 'heroicon-o-truck',
        };
    }

    public function isInternal(): bool
    {
        return $this === self::INTERNAL;
    }

    public function isExternal(): bool
    {
        return ! $this->isInternal();
    }
}
