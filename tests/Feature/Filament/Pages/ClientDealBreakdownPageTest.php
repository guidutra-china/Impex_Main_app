<?php

namespace Tests\Feature\Filament\Pages;

use App\Domain\CRM\Models\Company;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Filament\Pages\ClientDealBreakdown;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Support\DealScenarioBuilder;
use Tests\TestCase;

class ClientDealBreakdownPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Register the permission this codebase uses for the page.
        // Spatie Permission is installed; ensure the permission exists for the
        // web guard before any user tries to receive it.
        if (class_exists(\Spatie\Permission\Models\Permission::class)) {
            \Spatie\Permission\Models\Permission::firstOrCreate([
                'name' => 'view-payments',
                'guard_name' => 'web',
            ]);
        }
    }

    public function test_unauthorized_user_cannot_access(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // Page gates via canAccess() which checks ->can('view-payments').
        // Without the permission, the gate should reject access.
        $this->assertFalse(ClientDealBreakdown::canAccess());
    }

    public function test_authorized_user_sees_select_client_prompt(): void
    {
        $this->actingAs($this->makeAuthorizedUser());

        Livewire::test(ClientDealBreakdown::class)
            ->assertSee(__('client_deal_breakdown.select_client_prompt'));
    }

    public function test_client_selection_loads_empty_state_when_no_deals(): void
    {
        $this->actingAs($this->makeAuthorizedUser());
        $client = Company::factory()->create(['status' => 'active']);

        Livewire::test(ClientDealBreakdown::class)
            ->set('clientId', $client->id)
            ->assertSee(__('client_deal_breakdown.empty_state'));
    }

    public function test_toggle_deal_expands_and_collapses(): void
    {
        $this->actingAs($this->makeAuthorizedUser());
        $client = Company::factory()->create(['status' => 'active']);

        DealScenarioBuilder::make()
            ->forClient($client)
            ->withPi(reference: 'PI-EXP')
            ->build();

        $piId = ProformaInvoice::where('reference', 'PI-EXP')->value('id');

        Livewire::test(ClientDealBreakdown::class)
            ->set('clientId', $client->id)
            ->assertSet('expandedDeals', [])
            ->call('toggleDeal', $piId)
            ->assertSet('expandedDeals', [$piId])
            ->call('toggleDeal', $piId)
            ->assertSet('expandedDeals', []);
    }

    private function makeAuthorizedUser(): User
    {
        $user = User::factory()->create();

        if (class_exists(\Spatie\Permission\Models\Permission::class)
            && method_exists($user, 'givePermissionTo')) {
            $user->givePermissionTo('view-payments');
        } else {
            // Fallback: attach a wildcard Gate::before — narrowly scoped to this user.
            Gate::before(fn (User $u) => $u->id === $user->id ? true : null);
        }

        return $user;
    }
}
