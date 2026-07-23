<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Domain\CRM\Actions\ResolveOrCreateCompanyAction;
use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveOrCreateCompanyActionTest extends TestCase
{
    use RefreshDatabase;

    private function action(): ResolveOrCreateCompanyAction
    {
        return app(ResolveOrCreateCompanyAction::class);
    }

    public function test_creates_company_with_role_when_no_match_exists(): void
    {
        $result = $this->action()->execute('Shenzhen Nova Lighting Co., Ltd', [
            'address_city' => 'Shenzhen',
        ], CompanyRole::SUPPLIER);

        $this->assertTrue($result->wasCreated);
        $this->assertSame('Shenzhen Nova Lighting Co., Ltd', $result->company->name);
        $this->assertSame('Shenzhen', $result->company->address_city);
        $this->assertTrue($result->company->companyRoles()->where('role', CompanyRole::SUPPLIER->value)->exists());
        $this->assertSame(1, Company::count());
    }

    public function test_reuses_company_when_name_differs_only_by_case_and_whitespace(): void
    {
        $existing = Company::factory()->create(['name' => 'Shenzhen Nova Lighting Co., Ltd']);

        $result = $this->action()->execute("  shenzhen  NOVA lighting co., ltd \n");

        $this->assertFalse($result->wasCreated);
        $this->assertSame($existing->id, $result->company->id);
        $this->assertSame(1, Company::count());
    }

    public function test_reuses_company_by_tax_number_even_when_name_differs(): void
    {
        $existing = Company::factory()->create([
            'name' => 'ABC Lighting',
            'tax_number' => '91440300MA5DA1234X',
        ]);

        $result = $this->action()->execute('Shenzhen ABC Lighting Co., Ltd', [
            'tax_number' => '91440300MA5DA1234X',
        ]);

        $this->assertFalse($result->wasCreated);
        $this->assertSame($existing->id, $result->company->id);
        $this->assertSame(1, Company::count());
    }

    public function test_fills_only_blank_fields_on_reuse(): void
    {
        $existing = Company::factory()->create([
            'name' => 'Nova Fornecedora Ltd',
            'phone' => '+86 ORIGINAL',
            'email' => null,
        ]);

        $result = $this->action()->execute('nova fornecedora ltd', [
            'phone' => '+86 NEW',
            'email' => 'sales@nova.cn',
        ]);

        $this->assertFalse($result->wasCreated);
        $this->assertSame('+86 ORIGINAL', $result->company->fresh()->phone);
        $this->assertSame('sales@nova.cn', $result->company->fresh()->email);
    }

    public function test_does_not_match_soft_deleted_companies(): void
    {
        $trashed = Company::factory()->create(['name' => 'Old Supplier Ltd']);
        $trashed->delete();

        $result = $this->action()->execute('Old Supplier Ltd');

        $this->assertTrue($result->wasCreated);
        $this->assertNotSame($trashed->id, $result->company->id);
    }

    public function test_ensures_role_on_reuse_without_duplicating(): void
    {
        $existing = Company::factory()->create(['name' => 'Nova Fornecedora Ltd']);
        $existing->companyRoles()->create(['role' => CompanyRole::SUPPLIER->value]);

        $result = $this->action()->execute('Nova Fornecedora Ltd', [], CompanyRole::SUPPLIER);

        $this->assertFalse($result->wasCreated);
        $this->assertSame(1, $result->company->companyRoles()->where('role', CompanyRole::SUPPLIER->value)->count());
    }

    public function test_saving_a_company_maintains_name_normalized(): void
    {
        $company = Company::factory()->create(['name' => '  Foshan  Deli   Hardware ']);

        $this->assertSame('FOSHAN DELI HARDWARE', $company->fresh()->name_normalized);
    }

    public function test_blank_name_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->action()->execute('   ');
    }
}
