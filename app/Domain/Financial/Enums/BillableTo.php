<?php

namespace App\Domain\Financial\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum BillableTo: string implements HasLabel, HasColor, HasIcon
{
    case CLIENT = 'client';
    case SUPPLIER = 'supplier';
    case COMPANY = 'company';

    public function getLabel(): string
    {
        return match ($this) {
            self::CLIENT => 'Client (Repass)',
            self::SUPPLIER => 'Supplier (Repass)',
            self::COMPANY => 'Company (Internal)',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::CLIENT => 'success',
            self::SUPPLIER => 'warning',
            self::COMPANY => 'danger',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::CLIENT => 'heroicon-o-arrow-up-right',
            self::SUPPLIER => 'heroicon-o-arrow-down-left',
            self::COMPANY => 'heroicon-o-building-office',
        };
    }
}
