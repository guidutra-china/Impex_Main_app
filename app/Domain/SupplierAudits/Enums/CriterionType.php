<?php

namespace App\Domain\SupplierAudits\Enums;

use Filament\Support\Contracts\HasLabel;

enum CriterionType: string implements HasLabel
{
    case SCORED = 'scored';
    case PASS_FAIL = 'pass_fail';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::SCORED => 'Scored (1-5)',
            self::PASS_FAIL => 'Pass / Fail',
        };
    }
}
