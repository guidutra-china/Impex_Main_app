<?php

namespace App\Domain\Logistics\DTOs;

final readonly class PendingMultiBoxSet
{
    /**
     * @param  array<int, string>  $partLabels
     */
    public function __construct(
        public string $setId,
        public int $shipmentItemId,
        public int $piecesPerUnit,
        public array $partLabels,
    ) {}
}
