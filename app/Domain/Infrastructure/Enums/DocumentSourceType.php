<?php

namespace App\Domain\Infrastructure\Enums;

use Filament\Support\Contracts\HasLabel;

enum DocumentSourceType: string implements HasLabel
{
    case GENERATED = 'generated';
    case UPLOADED = 'uploaded';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::GENERATED => 'Generated',
            self::UPLOADED => 'Uploaded',
        };
    }
}
