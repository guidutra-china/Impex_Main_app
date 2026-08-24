<?php

namespace Tests\Unit\Logistics;

use App\Domain\Logistics\Models\ShipmentPallet;
use Tests\TestCase;

class ShipmentPalletTotalsTest extends TestCase
{
    public function test_volume_comes_from_the_three_dimensions(): void
    {
        $pallet = new ShipmentPallet(['length' => 115, 'width' => 150, 'height' => 100]);

        // 115 × 150 × 100 cm = 1,725 m³
        $this->assertEqualsWithDelta(1.725, $pallet->volume, 0.000001);
    }

    public function test_volume_is_null_when_any_dimension_is_missing(): void
    {
        $this->assertNull((new ShipmentPallet(['length' => 115, 'width' => 150]))->volume);
        $this->assertNull((new ShipmentPallet(['length' => 115, 'width' => 150, 'height' => 0]))->volume);
        $this->assertNull((new ShipmentPallet)->volume);
    }

    public function test_own_gross_weight_wins_over_the_boxes(): void
    {
        $pallet = new ShipmentPallet(['gross_weight' => 430.0]);

        $this->assertSame(430.0, $pallet->effectiveGrossWeight(402.0));
    }

    public function test_gross_weight_falls_back_to_the_boxes(): void
    {
        $this->assertSame(402.0, (new ShipmentPallet)->effectiveGrossWeight(402.0));
        // Zero não é pesagem: um pallet de 0 kg zeraria o total em silêncio.
        $this->assertSame(402.0, (new ShipmentPallet(['gross_weight' => 0]))->effectiveGrossWeight(402.0));
    }

    public function test_own_volume_wins_over_the_boxes(): void
    {
        $pallet = new ShipmentPallet(['length' => 115, 'width' => 150, 'height' => 100]);

        $this->assertEqualsWithDelta(1.725, $pallet->effectiveVolume(1.2), 0.000001);
    }

    public function test_volume_falls_back_to_the_boxes(): void
    {
        $this->assertSame(1.2, (new ShipmentPallet(['length' => 115]))->effectiveVolume(1.2));
    }
}
