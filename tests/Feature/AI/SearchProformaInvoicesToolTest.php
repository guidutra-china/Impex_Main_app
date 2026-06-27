<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Domain\AI\Tools\SearchProformaInvoicesTool;
use App\Domain\CRM\Models\Company;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SearchProformaInvoicesToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'view-proforma-invoices', 'guard_name' => 'web']);
    }

    public function test_authorize_mirrors_permission(): void
    {
        $allowed = User::factory()->create();
        $allowed->givePermissionTo('view-proforma-invoices');

        $tool = new SearchProformaInvoicesTool;

        $this->assertTrue($tool->authorize($allowed));
        $this->assertFalse($tool->authorize(User::factory()->create()));
    }

    public function test_run_returns_formatted_totals(): void
    {
        $company = Company::factory()->create(['name' => 'Initech']);
        ProformaInvoice::factory()->create(['reference' => 'PI-777', 'company_id' => $company->id, 'currency_code' => 'USD']);

        $result = (new SearchProformaInvoicesTool)->run(['referencia' => '777'], User::factory()->create());

        $this->assertSame(1, $result['count']);
        $this->assertSame('PI-777', $result['proforma_invoices'][0]['reference']);
        $this->assertSame('Initech', $result['proforma_invoices'][0]['parte']);
        $this->assertSame('USD 0.00', $result['proforma_invoices'][0]['total']);
        $this->assertSame('USD 0.00', $result['proforma_invoices'][0]['grand_total']);
    }
}
