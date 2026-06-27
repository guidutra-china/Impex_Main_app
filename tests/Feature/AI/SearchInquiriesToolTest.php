<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Domain\AI\Tools\SearchInquiriesTool;
use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SearchInquiriesToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'view-inquiries', 'guard_name' => 'web']);
    }

    public function test_authorize_mirrors_permission(): void
    {
        $allowed = User::factory()->create();
        $allowed->givePermissionTo('view-inquiries');
        $denied = User::factory()->create();

        $tool = new SearchInquiriesTool;

        $this->assertTrue($tool->authorize($allowed));
        $this->assertFalse($tool->authorize($denied));
    }

    public function test_run_filters_by_reference_and_returns_summary(): void
    {
        $company = Company::factory()->create(['name' => 'Acme Ltd']);
        Inquiry::factory()->create(['reference' => 'INQ-AAA', 'company_id' => $company->id]);
        Inquiry::factory()->create(['reference' => 'INQ-BBB', 'company_id' => $company->id]);

        $user = User::factory()->create();
        $result = (new SearchInquiriesTool)->run(['referencia' => 'AAA'], $user);

        $this->assertSame(1, $result['count']);
        $this->assertSame('INQ-AAA', $result['inquiries'][0]['reference']);
        $this->assertSame('Acme Ltd', $result['inquiries'][0]['cliente']);
    }
}
