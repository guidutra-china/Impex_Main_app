<?php

namespace App\Domain\Financial\Reports\DTOs;

use App\Domain\Financial\Enums\AdditionalCostType;

final readonly class AdditionalCostRow
{
    public function __construct(
        public string $label,
        public AdditionalCostType $type,
        public int $totalOriginal,
        public int $attributedOriginal,
        public ?int $attributedPresentation,
    ) {
    }
}
