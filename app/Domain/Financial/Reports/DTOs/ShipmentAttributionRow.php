<?php

namespace App\Domain\Financial\Reports\DTOs;

final readonly class ShipmentAttributionRow
{
    /** @param  list<AdditionalCostRow>  $additionalCosts */
    public function __construct(
        public int $id,
        public string $reference,
        public ?string $clientReference,
        public ?string $forwarderName,
        public string $currencyOriginal,
        public int $totalCostOriginal,
        public float $attributionPct,
        public AttributionBasis $basis,
        public int $attributedOriginal,
        public ?int $attributedPresentation,
        public int $paidOriginal,
        public ?int $paidPresentation,
        public int $outstandingOriginal,
        public ?int $outstandingPresentation,
        public string $detailUrl,
        public array $additionalCosts,
    ) {
    }
}
