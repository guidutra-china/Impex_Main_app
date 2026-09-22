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

    /**
     * Mesma regra da aba do embarque: parcelas iguais viram um grupo
     * recolhido com a soma; expandir mostra de quais PIs são.
     */
    public function test_equal_installments_are_grouped_collapsed_with_their_sum(): void
    {
        $company = Company::factory()->create();
        $this->actingAsPortalUser($company);

        $due = now()->addDays(10);
        foreach ([['PI-2026-00015', 437_375_000], ['PI-2026-00017', 712_417_100]] as [$reference, $amount]) {
            $pi = ProformaInvoice::factory()->create(['company_id' => $company->id, 'reference' => $reference]);
            PaymentScheduleItem::factory()->create([
                'payable_type' => ProformaInvoice::class,
                'payable_id' => $pi->id,
                'label' => '30% — Before Shipment — [SH-2026-00056 / '.$reference.']',
                'percentage' => 30,
                'status' => PaymentScheduleStatus::PENDING,
                'due_condition' => \App\Domain\Settings\Enums\CalculationBase::BEFORE_SHIPMENT,
                'due_date' => $due,
                'amount' => $amount,
                'currency_code' => 'USD',
                'is_credit' => false,
            ]);
        }

        Livewire::test(AccountsPayablePage::class)
            ->assertOk()
            // Cabeçalho do grupo: título limpo, quantidade e soma (43.737,50 + 71.241,71).
            ->assertSee('30% — Before Shipment')
            ->assertSee('2 installments')
            ->assertSee('114,979.21')
            // Recolhido por padrão, com as duas PIs dentro.
            ->assertSeeHtml('x-data="{ open: false }"')
            ->assertSee('PI-2026-00015')
            ->assertSee('PI-2026-00017');
    }

    public function test_a_single_installment_stays_a_plain_row(): void
    {
        $company = Company::factory()->create();
        $this->actingAsPortalUser($company);

        $pi = ProformaInvoice::factory()->create(['company_id' => $company->id]);
        PaymentScheduleItem::factory()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'label' => '100% — Order Date',
            'status' => PaymentScheduleStatus::PENDING,
            'due_condition' => \App\Domain\Settings\Enums\CalculationBase::ORDER_DATE,
            'due_date' => now()->addDays(10),
            'amount' => 100_000_000,
            'currency_code' => 'USD',
            'is_credit' => false,
        ]);

        Livewire::test(AccountsPayablePage::class)
            ->assertOk()
            ->assertSee('100% — Order Date')
            ->assertDontSeeHtml('x-data="{ open: false }"');
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
