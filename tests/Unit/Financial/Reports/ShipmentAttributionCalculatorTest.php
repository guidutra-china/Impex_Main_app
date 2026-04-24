<?php

namespace Tests\Unit\Financial\Reports;

use App\Domain\Financial\Reports\DTOs\AttributionBasis;
use App\Domain\Financial\Reports\Support\ShipmentAttributionCalculator;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

class ShipmentAttributionCalculatorTest extends TestCase
{
    public function test_weight_based_attribution(): void
    {
        $pi = $this->makePi([1, 2]);
        $shipment = $this->makeShipment([
            ['pi_item_id' => 1, 'total_weight' => 100.0, 'total_volume' => 0, 'quantity' => 10],
            ['pi_item_id' => 2, 'total_weight' => 200.0, 'total_volume' => 0, 'quantity' => 10],
            ['pi_item_id' => 99, 'total_weight' => 200.0, 'total_volume' => 0, 'quantity' => 10],
        ]);

        $result = (new ShipmentAttributionCalculator())->calculate($shipment, $pi);

        $this->assertSame(AttributionBasis::WEIGHT, $result->basis);
        $this->assertEqualsWithDelta(0.6, $result->pct, 0.0001);
    }

    public function test_volume_fallback_when_weight_zero(): void
    {
        $pi = $this->makePi([1]);
        $shipment = $this->makeShipment([
            ['pi_item_id' => 1, 'total_weight' => 0, 'total_volume' => 3.0, 'quantity' => 10],
            ['pi_item_id' => 99, 'total_weight' => 0, 'total_volume' => 7.0, 'quantity' => 10],
        ]);

        $result = (new ShipmentAttributionCalculator())->calculate($shipment, $pi);

        $this->assertSame(AttributionBasis::VOLUME, $result->basis);
        $this->assertEqualsWithDelta(0.3, $result->pct, 0.0001);
    }

    public function test_quantity_fallback_when_weight_and_volume_zero(): void
    {
        $pi = $this->makePi([1]);
        $shipment = $this->makeShipment([
            ['pi_item_id' => 1, 'total_weight' => 0, 'total_volume' => 0, 'quantity' => 5],
            ['pi_item_id' => 99, 'total_weight' => 0, 'total_volume' => 0, 'quantity' => 15],
        ]);

        $result = (new ShipmentAttributionCalculator())->calculate($shipment, $pi);

        $this->assertSame(AttributionBasis::QUANTITY, $result->basis);
        $this->assertEqualsWithDelta(0.25, $result->pct, 0.0001);
    }

    public function test_single_pi_shipment_gets_full_attribution(): void
    {
        $pi = $this->makePi([1]);
        $shipment = $this->makeShipment([
            ['pi_item_id' => 1, 'total_weight' => 100.0, 'total_volume' => 0, 'quantity' => 10],
        ]);

        $result = (new ShipmentAttributionCalculator())->calculate($shipment, $pi);

        $this->assertEqualsWithDelta(1.0, $result->pct, 0.0001);
    }

    public function test_zero_pct_when_pi_not_in_shipment(): void
    {
        $pi = $this->makePi([42]);
        $shipment = $this->makeShipment([
            ['pi_item_id' => 1, 'total_weight' => 100.0, 'total_volume' => 0, 'quantity' => 10],
        ]);

        $result = (new ShipmentAttributionCalculator())->calculate($shipment, $pi);

        $this->assertSame(0.0, $result->pct);
    }

    public function test_none_basis_when_all_dimensions_are_zero(): void
    {
        $pi = $this->makePi([1]);
        $shipment = $this->makeShipment([
            ['pi_item_id' => 1, 'total_weight' => 0, 'total_volume' => 0, 'quantity' => 0],
        ]);
        // The value-cascade iterates items and reads `proformaInvoiceItem`; pre-set
        // the relation to null to avoid a DB lookup on an unsaved model.
        foreach ($shipment->items as $item) {
            $item->setRelation('proformaInvoiceItem', null);
        }

        $result = (new ShipmentAttributionCalculator())->calculate($shipment, $pi);

        $this->assertSame(AttributionBasis::NONE, $result->basis);
        $this->assertSame(0.0, $result->pct);
    }

    private function makePi(array $itemIds): ProformaInvoice
    {
        $pi = new ProformaInvoice();
        $pi->id = 500;
        $pi->setRelation('items', new Collection(array_map(function (int $id) {
            $item = new ProformaInvoiceItem();
            $item->id = $id;
            return $item;
        }, $itemIds)));

        return $pi;
    }

    /**
     * @param  list<array{pi_item_id:int,total_weight:float,total_volume:float,quantity:int}>  $rows
     */
    private function makeShipment(array $rows): Shipment
    {
        $shipment = new Shipment();
        $shipment->id = 900;
        $shipment->setRelation('items', new Collection(array_map(function (array $r) {
            $item = new ShipmentItem();
            $item->proforma_invoice_item_id = $r['pi_item_id'];
            $item->total_weight = $r['total_weight'];
            $item->total_volume = $r['total_volume'];
            $item->quantity = $r['quantity'];
            return $item;
        }, $rows)));
        return $shipment;
    }
}
