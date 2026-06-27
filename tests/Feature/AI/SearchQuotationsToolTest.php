<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Domain\AI\Tools\SearchQuotationsTool;
use App\Domain\CRM\Models\Company;
use App\Domain\Quotations\Models\Quotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SearchQuotationsToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'view-quotations', 'guard_name' => 'web']);
    }

    public function test_authorize_mirrors_permission(): void
    {
        $allowed = User::factory()->create();
        $allowed->givePermissionTo('view-quotations');

        $tool = new SearchQuotationsTool;

        $this->assertTrue($tool->authorize($allowed));
        $this->assertFalse($tool->authorize(User::factory()->create()));
    }

    public function test_run_returns_formatted_total(): void
    {
        $company = Company::factory()->create(['name' => 'Globex']);
        Quotation::factory()->create(['reference' => 'Q-XYZ', 'company_id' => $company->id, 'currency_code' => 'USD']);

        $result = (new SearchQuotationsTool)->run(['referencia' => 'XYZ'], User::factory()->create());

        $this->assertSame(1, $result['count']);
        $this->assertSame('Q-XYZ', $result['quotations'][0]['reference']);
        $this->assertSame('Globex', $result['quotations'][0]['cliente']);
        $this->assertSame('USD 0.00', $result['quotations'][0]['total']);
    }
}
