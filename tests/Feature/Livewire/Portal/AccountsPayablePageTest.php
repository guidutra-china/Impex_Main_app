<?php

namespace Tests\Feature\Livewire\Portal;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Filament\Portal\Pages\AccountsPayablePage;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AccountsPayablePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The page is a Filament Page; rendering it via Livewire::test needs the
        // owning panel set as current, otherwise the snapshot has no panel context.
        Filament::setCurrentPanel('portal');

        // canAccess() gates on this permission (added Apr 30 financial Pages gate).
        Permission::firstOrCreate(['name' => 'portal:view-financial-summary', 'guard_name' => 'web']);
    }

    private function actingAsPortalUser(Company $company): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->givePermissionTo('portal:view-financial-summary');
        $this->actingAs($user);

        // Portal panel is multi-tenant (portal/{tenant}/...); resource URLs in the
        // table partial need the current tenant set, or url generation throws.
        Filament::setTenant($company);

        return $user;
    }

    public function test_page_loads_for_authenticated_user_with_company(): void
    {
        $company = Company::factory()->create();
        $this->actingAsPortalUser($company);

        $pi = ProformaInvoice::factory()->create(['company_id' => $company->id]);
        PaymentScheduleItem::factory()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'status' => PaymentScheduleStatus::PENDING,
            'due_date' => now()->addDays(10),
            'amount' => 123_45,
            'currency_code' => 'USD',
            'is_credit' => false,
        ]);

        Livewire::test(AccountsPayablePage::class)
            ->assertOk()
            ->assertSee('USD');
    }

    public function test_user_without_company_receives_403(): void
    {
        $user = User::factory()->create(['company_id' => null]);
        $this->actingAs($user);

        Livewire::test(AccountsPayablePage::class)
            ->assertStatus(403);
    }

    public function test_user_a_does_not_see_company_b_items(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $this->actingAsPortalUser($companyA);

        $piB = ProformaInvoice::factory()->create(['company_id' => $companyB->id]);
        PaymentScheduleItem::factory()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $piB->id,
            'status' => PaymentScheduleStatus::PENDING,
            'due_date' => now()->addDays(5),
            'amount' => 999_99,
            'currency_code' => 'EUR',
            'is_credit' => false,
        ]);

        Livewire::test(AccountsPayablePage::class)
            ->assertOk()
            ->assertDontSee('EUR')
            ->assertDontSee('999.99');
    }

    public function test_preset_and_toggles_change_rendered_data(): void
    {
        $company = Company::factory()->create();
        $this->actingAsPortalUser($company);

        $pi = ProformaInvoice::factory()->create(['company_id' => $company->id]);
        // Paid item in next 30 days — hidden when includePaid = false
        PaymentScheduleItem::factory()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'status' => PaymentScheduleStatus::PAID,
            'due_date' => now()->addDays(5),
            // Amount is stored at scale 10000 (the table renders amount / 10000).
            'amount' => 777_0000,
            'currency_code' => 'USD',
            'is_credit' => false,
        ]);

        Livewire::test(AccountsPayablePage::class)
            ->set('preset', '30')
            ->set('includePaid', false)
            ->assertDontSee('777.00')
            ->set('includePaid', true)
            ->assertSee('777.00');
    }
}
