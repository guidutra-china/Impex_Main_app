<?php

namespace App\Domain\Financial\Reports\DTOs;

enum AttributionBasis: string
{
    case WEIGHT = 'weight';
    case VOLUME = 'volume';
    case QUANTITY = 'quantity';
    case VALUE = 'value';
    case NONE = 'none';

    public function labelKey(): string
    {
        return 'client_deal_breakdown.basis.' . $this->value;
    }
}
