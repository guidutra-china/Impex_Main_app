<?php

namespace Tests\Feature\Logistics;

use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Actions\RecalculateShipmentTotalsAction;
use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\Logistics\Models\ShipmentPallet;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecalculateShipmentTotalsFromCartonsTest extends TestCase
{
    use RefreshDatabase;

    private RecalculateShipmentTotalsAction $recalc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recalc = app(RecalculateShipmentTotalsAction::class);
    }

    private function makeShipment(): array
    {
        $client = Company::create(['name' => 'Client RS-'.uniqid(), 'status' => 'active']);
        $client->companyRoles()->create(['role' => 'client']);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-RS-'.uniqid(),
            'company_id' => $client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $pi = ProformaInvoice::create([
            'reference' => 'PI-RS-'.uniqid(),
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-04-08',
            'status' => 'confirmed',
        ]);

        $piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description' => 'Product',
            'quantity' => 100,
            'unit_price' => 1000,
            'unit' => 'pcs',
        ]);

        $shipment = Shipment::create([
            'reference' => 'SHIP-RS-'.uniqid(),
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'status' => 'draft',
            'transport_mode' => 'sea',
            'origin_port' => 'Shanghai',
            'destination_port' => 'Santos',
        ]);

        return [$shipment, $piItem];
    }

    private function addCarton(Shipment $shipment, array $attrs): Carton
    {
        return Carton::create(array_merge([
            'shipment_id' => $shipment->id,
            'label' => 'BOX-'.str_pad((string) ($shipment->cartons()->count() + 1), 3, '0', STR_PAD_LEFT),
            'packaging_type' => 'CARTON',
        ], $attrs));
    }

    public function test_cartons_totals_are_aggregated(): void
    {
        [$shipment] = $this->makeShipment();

        $this->addCarton($shipment, ['gross_weight' => 10.5, 'net_weight' => 9.0, 'volume' => 0.040]);
        $this->addCarton($shipment, ['gross_weight' => 15.0, 'net_weight' => 13.5, 'volume' => 0.060]);
        $this->addCarton($shipment, ['gross_weight' => 7.2, 'net_weight' => 6.0, 'volume' => 0.025]);

        $this->recalc->execute($shipment);

        $shipment->refresh();
        $this->assertEquals(3, $shipment->total_packages);
        $this->assertEquals('32.700', $shipment->total_gross_weight);
        $this->assertEquals('28.500', $shipment->total_net_weight);
        $this->assertEquals('0.1250', $shipment->total_volume);
    }

    public function test_total_packages_counts_a_pallet_as_one_package(): void
    {
        [$shipment] = $this->makeShipment();

        $pallet = ShipmentPallet::create([
            'shipment_id' => $shipment->id,
            'label' => 'PLT-001',
        ]);

        $this->addCarton($shipment, ['gross_weight' => 10.0]);
        $this->addCarton($shipment, ['gross_weight' => 10.0]);
        $this->addCarton($shipment, ['gross_weight' => 10.0, 'shipment_pallet_id' => $pallet->id]);
        $this->addCarton($shipment, ['gross_weight' => 10.0, 'shipment_pallet_id' => $pallet->id]);
        $this->addCarton($shipment, ['gross_weight' => 10.0, 'shipment_pallet_id' => $pallet->id]);

        $this->recalc->execute($shipment);

        $shipment->refresh();
        // 2 caixas soltas + 1 pallet = 3 volumes (as 3 caixas do pallet não contam).
        $this->assertEquals(3, $shipment->total_packages);
        // Peso segue somando todas as caixas.
        $this->assertEquals('50.000', $shipment->total_gross_weight);
    }

    public function test_pallet_weight_and_cubic_replace_the_boxes_in_the_stored_totals(): void
    {
        [$shipment] = $this->makeShipment();

        $pallet = ShipmentPallet::create([
            'shipment_id' => $shipment->id,
            'label' => 'PLT-001',
            'gross_weight' => 430.0,
            'length' => 115,
            'width' => 150,
            'height' => 100,
        ]);

        $this->addCarton($shipment, ['gross_weight' => 10.0, 'net_weight' => 9.0, 'volume' => 0.1]);
        $this->addCarton($shipment, ['gross_weight' => 10.0, 'net_weight' => 9.0, 'volume' => 0.1, 'shipment_pallet_id' => $pallet->id]);
        $this->addCarton($shipment, ['gross_weight' => 10.0, 'net_weight' => 9.0, 'volume' => 0.1, 'shipment_pallet_id' => $pallet->id]);

        $this->recalc->execute($shipment);

        $shipment->refresh();
        $this->assertEquals(2, $shipment->total_packages);
        // 10 (caixa solta) + 430 (pallet pesado), não 30.
        $this->assertEquals('440.000', $shipment->total_gross_weight);
        // 0.1 (caixa solta) + 1.725 (cubo do conjunto), não 0.3.
        $this->assertEquals('1.8250', $shipment->total_volume);
        // Líquido segue somando todas as caixas.
        $this->assertEquals('27.000', $shipment->total_net_weight);
    }

    public function test_fallback_to_shipment_items_when_no_cartons(): void
    {
        [$shipment, $piItem] = $this->makeShipment();

        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $piItem->id,
            'quantity' => 100,
            'total_weight' => 500.0,
            'total_volume' => 2.500,
            'sort_order' => 0,
        ]);

        $this->recalc->execute($shipment);

        $shipment->refresh();
        $this->assertNull($shipment->total_packages);
        $this->assertEquals('500.000', $shipment->total_gross_weight);
        $this->assertEquals('2.5000', $shipment->total_volume);
    }

    public function test_no_cartons_and_no_items_nulls_totals(): void
    {
        [$shipment] = $this->makeShipment();

        $this->recalc->execute($shipment);

        $shipment->refresh();
        $this->assertNull($shipment->total_packages);
        $this->assertNull($shipment->total_gross_weight);
    }

    public function test_cartons_with_null_weights_coalesce_to_zero(): void
    {
        [$shipment] = $this->makeShipment();

        $this->addCarton($shipment, ['gross_weight' => null, 'net_weight' => null, 'volume' => null]);
        $this->addCarton($shipment, ['gross_weight' => 5.0, 'net_weight' => 4.0, 'volume' => 0.010]);

        $this->recalc->execute($shipment);

        $shipment->refresh();
        $this->assertEquals(2, $shipment->total_packages);
        $this->assertEquals('5.000', $shipment->total_gross_weight);
    }

    public function test_currency_sync_still_works(): void
    {
        [$shipment, $piItem] = $this->makeShipment();

        // Unset currency to force sync
        $shipment->updateQuietly(['currency_code' => null]);

        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $piItem->id,
            'quantity' => 100,
            'sort_order' => 0,
        ]);

        $this->recalc->execute($shipment);

        $this->assertEquals('USD', $shipment->fresh()->currency_code);
    }
}
