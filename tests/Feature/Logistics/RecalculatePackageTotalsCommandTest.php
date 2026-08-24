<?php

namespace Tests\Feature\Logistics;

use App\Domain\CRM\Models\Company;
use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentPallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecalculatePackageTotalsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeShipment(string $suffix): Shipment
    {
        $client = Company::create(['name' => 'Client RPT-'.$suffix, 'status' => 'active']);
        $client->companyRoles()->create(['role' => 'client']);

        return Shipment::create([
            'reference' => 'SHIP-RPT-'.$suffix,
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'status' => 'draft',
            'transport_mode' => 'sea',
            'origin_port' => 'Shanghai',
            'destination_port' => 'Santos',
            // Valor gravado pela regra antiga (contagem crua de caixas).
            'total_packages' => 5,
        ]);
    }

    private function fillWithPallet(Shipment $shipment): void
    {
        $pallet = ShipmentPallet::create(['shipment_id' => $shipment->id, 'label' => 'PLT-001']);

        for ($i = 1; $i <= 5; $i++) {
            Carton::create([
                'shipment_id' => $shipment->id,
                'label' => 'BOX-'.$i,
                'packaging_type' => 'CARTON',
                'gross_weight' => 10.0,
                'shipment_pallet_id' => $i > 2 ? $pallet->id : null,
            ]);
        }
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $shipment = $this->makeShipment('dry');
        $this->fillWithPallet($shipment);

        $this->artisan('shipments:recalculate-package-totals')
            ->expectsOutputToContain('Seriam atualizados: 1')
            ->assertExitCode(0);

        $this->assertEquals(5, $shipment->fresh()->total_packages);
    }

    public function test_apply_rewrites_total_packages_as_shipping_units(): void
    {
        $shipment = $this->makeShipment('apply');
        $this->fillWithPallet($shipment);

        $this->artisan('shipments:recalculate-package-totals', ['--apply' => true])
            ->assertExitCode(0);

        // 2 caixas soltas + 1 pallet = 3 volumes.
        $this->assertEquals(3, $shipment->fresh()->total_packages);
    }

    public function test_shipments_without_pallets_are_untouched(): void
    {
        $shipment = $this->makeShipment('nopallet');
        Carton::create(['shipment_id' => $shipment->id, 'label' => 'BOX-1', 'packaging_type' => 'CARTON']);

        $this->artisan('shipments:recalculate-package-totals', ['--apply' => true])
            ->expectsOutputToContain('Nenhum embarque com caixa em pallet')
            ->assertExitCode(0);

        $this->assertEquals(5, $shipment->fresh()->total_packages);
    }
}
