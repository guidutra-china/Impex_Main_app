<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Domain\AI\Tools\ProductionStatusTool;
use App\Domain\Planning\Models\ProductionSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProductionStatusToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'view-production-schedules', 'guard_name' => 'web']);
    }

    public function test_authorize_mirrors_permission(): void
    {
        $allowed = User::factory()->create();
        $allowed->givePermissionTo('view-production-schedules');

        $tool = new ProductionStatusTool;

        $this->assertTrue($tool->authorize($allowed));
        $this->assertFalse($tool->authorize(User::factory()->create()));
    }

    public function test_run_filters_by_reference_and_reports_quantities(): void
    {
        ProductionSchedule::factory()->create(['reference' => 'PS-100']);
        ProductionSchedule::factory()->create(['reference' => 'PS-200']);

        $result = (new ProductionStatusTool)->run(['referencia' => 'PS-100'], User::factory()->create());

        $this->assertSame(1, $result['count']);
        $this->assertSame('PS-100', $result['production_schedules'][0]['reference']);
        $this->assertArrayHasKey('qtd_planejada', $result['production_schedules'][0]);
        $this->assertArrayHasKey('qtd_real', $result['production_schedules'][0]);
    }
}
