<?php

namespace Tests\Feature\Logistics;

use App\Domain\CRM\Models\Company;
use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentPallet;
use App\Domain\Logistics\Services\PackingTotalsCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PackingTotalsCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private PackingTotalsCalculator $calculator;

    private Shipment $shipment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = app(PackingTotalsCalculator::class);

        $client = Company::create(['name' => 'Client PTC-'.uniqid(), 'status' => 'active']);
        $client->companyRoles()->create(['role' => 'client']);

        $this->shipment = Shipment::create([
            'reference' => 'SHIP-PTC-'.uniqid(),
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'status' => 'draft',
            'transport_mode' => 'sea',
            'origin_port' => 'Shanghai',
            'destination_port' => 'Santos',
        ]);
    }

    private function carton(array $attrs = []): Carton
    {
        return Carton::create(array_merge([
            'shipment_id' => $this->shipment->id,
            'label' => 'BOX-'.str_pad((string) ($this->shipment->cartons()->count() + 1), 3, '0', STR_PAD_LEFT),
            'packaging_type' => 'CARTON',
            'gross_weight' => 10.0,
            'net_weight' => 9.0,
            'volume' => 0.100000,
        ], $attrs));
    }

    private function pallet(array $attrs = []): ShipmentPallet
    {
        return ShipmentPallet::create(array_merge([
            'shipment_id' => $this->shipment->id,
            'label' => 'PLT-'.str_pad((string) (ShipmentPallet::where('shipment_id', $this->shipment->id)->count() + 1), 3, '0', STR_PAD_LEFT),
        ], $attrs));
    }

    /**
     * As duas formas de calcular (coleção carregada e agregação no banco)
     * precisam devolver exatamente o mesmo, senão tela e documento divergem.
     */
    private function assertBothWaysAgree(): array
    {
        $fromShipment = $this->calculator->fromShipment($this->shipment->fresh());
        $fromCartons = $this->calculator->fromCartons(
            $this->shipment->fresh()->cartons()->with('pallet')->get()
        );

        $this->assertEquals($fromShipment, $fromCartons, 'fromShipment e fromCartons divergiram');

        return $fromShipment;
    }

    public function test_loose_cartons_sum_as_before(): void
    {
        $this->carton();
        $this->carton();

        $totals = $this->assertBothWaysAgree();

        $this->assertSame(2, $totals['units']);
        $this->assertSame(2, $totals['cartons']);
        $this->assertSame(0, $totals['pallets']);
        $this->assertEqualsWithDelta(20.0, $totals['gross'], 0.0001);
        $this->assertEqualsWithDelta(18.0, $totals['net'], 0.0001);
        $this->assertEqualsWithDelta(0.2, $totals['cbm'], 0.0001);
    }

    public function test_pallet_weight_and_cubic_replace_the_boxes(): void
    {
        $pallet = $this->pallet([
            'gross_weight' => 430.0,
            'length' => 115, 'width' => 150, 'height' => 100,
        ]);

        $this->carton();                                        // solta: 10 kg, 0.1 m³
        $this->carton(['shipment_pallet_id' => $pallet->id]);   // no pallet
        $this->carton(['shipment_pallet_id' => $pallet->id]);   // no pallet

        $totals = $this->assertBothWaysAgree();

        $this->assertSame(2, $totals['units']);   // 1 caixa solta + 1 pallet
        $this->assertSame(3, $totals['cartons']);
        $this->assertSame(1, $totals['pallets']);
        // 10 (solta) + 430 (pallet pesado), NÃO 10 + 20.
        $this->assertEqualsWithDelta(440.0, $totals['gross'], 0.0001);
        // 0.1 (solta) + 1.725 (cubo do conjunto), NÃO 0.1 + 0.2.
        $this->assertEqualsWithDelta(1.825, $totals['cbm'], 0.0001);
        // Líquido continua vindo das caixas, inclusive as paletizadas.
        $this->assertEqualsWithDelta(27.0, $totals['net'], 0.0001);
    }

    public function test_pallet_without_weight_or_dimensions_falls_back_to_the_boxes(): void
    {
        $pallet = $this->pallet();

        $this->carton(['shipment_pallet_id' => $pallet->id]);
        $this->carton(['shipment_pallet_id' => $pallet->id]);

        $totals = $this->assertBothWaysAgree();

        $this->assertSame(1, $totals['units']);
        $this->assertEqualsWithDelta(20.0, $totals['gross'], 0.0001);
        $this->assertEqualsWithDelta(0.2, $totals['cbm'], 0.0001);
    }

    public function test_pallet_with_weight_only_still_takes_the_cubic_from_the_boxes(): void
    {
        $pallet = $this->pallet(['gross_weight' => 430.0]);

        $this->carton(['shipment_pallet_id' => $pallet->id]);
        $this->carton(['shipment_pallet_id' => $pallet->id]);

        $totals = $this->assertBothWaysAgree();

        $this->assertEqualsWithDelta(430.0, $totals['gross'], 0.0001);
        $this->assertEqualsWithDelta(0.2, $totals['cbm'], 0.0001);
    }

    public function test_empty_pallet_is_not_a_volume(): void
    {
        $this->pallet(['gross_weight' => 430.0, 'length' => 115, 'width' => 150, 'height' => 100]);
        $this->carton();

        $totals = $this->assertBothWaysAgree();

        // Pallet sem caixa não embarca: não conta volume nem arrasta peso/cubo.
        $this->assertSame(1, $totals['units']);
        $this->assertSame(0, $totals['pallets']);
        $this->assertEqualsWithDelta(10.0, $totals['gross'], 0.0001);
        $this->assertEqualsWithDelta(0.1, $totals['cbm'], 0.0001);
    }

    public function test_empty_shipment_is_all_zeros(): void
    {
        $totals = $this->assertBothWaysAgree();

        $this->assertSame(0, $totals['units']);
        $this->assertSame(0, $totals['cartons']);
        $this->assertEqualsWithDelta(0.0, $totals['gross'], 0.0001);
    }

    public function test_null_weights_do_not_break_the_sum(): void
    {
        $pallet = $this->pallet(['gross_weight' => 430.0]);

        $this->carton(['gross_weight' => null, 'net_weight' => null, 'volume' => null]);
        $this->carton(['shipment_pallet_id' => $pallet->id, 'gross_weight' => null, 'volume' => null]);

        $totals = $this->assertBothWaysAgree();

        $this->assertEqualsWithDelta(430.0, $totals['gross'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $totals['cbm'], 0.0001);
    }
}
