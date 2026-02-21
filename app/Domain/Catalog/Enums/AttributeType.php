<?php

namespace App\Domain\Catalog\Enums;

use Filament\Support\Contracts\HasLabel;

enum AttributeType: string implements HasLabel
{
    case TEXT = 'text';
    case NUMBER = 'number';
    case SELECT = 'select';
    case BOOLEAN = 'boolean';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::TEXT => 'Text',
            self::NUMBER => 'Number',
            self::SELECT => 'Select (Options)',
            self::BOOLEAN => 'Yes / No',
        };
    }
}
