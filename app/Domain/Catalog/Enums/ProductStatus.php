<?php

namespace App\Domain\Catalog\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ProductStatus: string implements HasLabel, HasColor
{
    case DRAFT = 'draft';
    case ACTIVE = 'active';
    case DISCONTINUED = 'discontinued';
    case OUT_OF_STOCK = 'out_of_stock';

    public function getLabel(): ?string
    {
        return __('enums.product_status.' . $this->value);
    }


    public function getColor(): string|array|null
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::ACTIVE => 'success',
            self::DISCONTINUED => 'danger',
            self::OUT_OF_STOCK => 'warning',
        };
    }

    public function getEnglishLabel(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::ACTIVE => 'Active',
            self::DISCONTINUED => 'Discontinued',
            self::OUT_OF_STOCK => 'Out of Stock',
        };
    }
}
