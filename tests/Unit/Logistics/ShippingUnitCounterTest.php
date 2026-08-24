<?php

namespace Tests\Unit\Logistics;

use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Services\ShippingUnitCounter;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ShippingUnitCounterTest extends TestCase
{
    private function cartons(?int ...$palletIds): Collection
    {
        return collect($palletIds)->map(fn (?int $id) => new Carton(['shipment_pallet_id' => $id]));
    }

    public function test_loose_cartons_each_count_as_one_unit(): void
    {
        $this->assertSame(3, ShippingUnitCounter::forCartons($this->cartons(null, null, null)));
    }

    public function test_a_pallet_counts_as_one_unit_no_matter_how_many_boxes_it_carries(): void
    {
        $this->assertSame(1, ShippingUnitCounter::forCartons($this->cartons(7, 7, 7, 7, 7, 7)));
    }

    public function test_mixes_loose_cartons_and_pallets(): void
    {
        // SH-2026-00042: 97 caixas fora de pallet + 2 pallets com 6 caixas cada.
        $cartons = $this->cartons(...array_merge(
            array_fill(0, 97, null),
            array_fill(0, 6, 2),
            array_fill(0, 6, 3),
        ));

        $this->assertSame(109, $cartons->count());
        $this->assertSame(99, ShippingUnitCounter::forCartons($cartons));
    }

    public function test_breakdown_reports_each_part(): void
    {
        $breakdown = ShippingUnitCounter::breakdown($this->cartons(null, null, 5, 5, 6));

        $this->assertSame([
            'units' => 4,
            'cartons' => 5,
            'loose_cartons' => 2,
            'pallets' => 2,
        ], $breakdown);
    }

    public function test_empty_collection_has_no_units(): void
    {
        $this->assertSame(0, ShippingUnitCounter::forCartons(collect()));
        $this->assertSame(
            ['units' => 0, 'cartons' => 0, 'loose_cartons' => 0, 'pallets' => 0],
            ShippingUnitCounter::breakdown(collect()),
        );
    }
}
