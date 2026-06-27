<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Domain\AI\Tools\SearchShipmentsTool;
use App\Domain\CRM\Models\Company;
use App\Domain\Logistics\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SearchShipmentsToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'view-shipments', 'guard_name' => 'web']);
    }

    public function test_authorize_mirrors_permission(): void
    {
        $allowed = User::factory()->create();
        $allowed->givePermissionTo('view-shipments');

        $tool = new SearchShipmentsTool;

        $this->assertTrue($tool->authorize($allowed));
        $this->assertFalse($tool->authorize(User::factory()->create()));
    }

    public function test_run_filters_by_reference(): void
    {
        $company = Company::factory()->create(['name' => 'Umbrella']);
        Shipment::factory()->create(['reference' => 'SH-900', 'company_id' => $company->id]);
        Shipment::factory()->create(['reference' => 'SH-901', 'company_id' => $company->id]);

        $result = (new SearchShipmentsTool)->run(['referencia' => '900'], User::factory()->create());

        $this->assertSame(1, $result['count']);
        $this->assertSame('SH-900', $result['shipments'][0]['reference']);
        $this->assertSame('Umbrella', $result['shipments'][0]['parte']);
    }
}
