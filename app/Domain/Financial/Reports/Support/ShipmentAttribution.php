<?php

namespace App\Domain\Financial\Reports\Support;

use App\Domain\Financial\Reports\DTOs\AttributionBasis;

/**
 * Result of ShipmentAttributionCalculator::calculate().
 *
 * @property-read float $pct  Ratio 0..1 (not a 0..100 percentage). Multiply by 100 for display.
 */
final readonly class ShipmentAttribution
{
    public function __construct(
        public float $pct,
        public AttributionBasis $basis,
    ) {
    }
}
