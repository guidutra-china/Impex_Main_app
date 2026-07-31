<?php

namespace Tests\Feature\Filament\Pages;

use App\Domain\Users\Enums\UserType;
use App\Filament\Pages\FinancialDashboard;
use App\Filament\Pages\OperationalDashboard;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class DashboardSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_store_default_dashboard_preference(): void
    {
        $user = User::factory()->create();

        $user->update(['default_dashboard' => 'financial']);

        $this->assertSame('financial', $user->fresh()->default_dashboard);
    }

    public function test_operational_dashboard_renders_for_authenticated_user(): void
    {
        $user = User::factory()->create([
            'type' => \App\Domain\Users\Enums\UserType::INTERNAL,
            'status' => 'active',
        ]);
        $this->actingAs($user);
        \Filament\Facades\Filament::setCurrentPanel('admin');

        $this->get(\App\Filament\Pages\OperationalDashboard::getUrl())->assertOk();
    }

    public function test_operational_dashboard_lists_operational_widgets(): void
    {
        $widgets = (new \App\Filament\Pages\OperationalDashboard)->getWidgets();

        $this->assertContains(\App\Filament\Widgets\OperationalAlertsWidget::class, $widgets);
        $this->assertContains(\App\Filament\Widgets\PipelineCountsWidget::class, $widgets);
        $this->assertContains(\App\Filament\Widgets\MyProjectsWidget::class, $widgets);
        $this->assertContains(\App\Filament\Widgets\SupplierAuditStatsWidget::class, $widgets);
    }

    public function test_financial_dashboard_is_forbidden_without_permission(): void
    {
        $user = User::factory()->create([
            'type' => UserType::INTERNAL,
            'status' => 'active',
        ]);
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');

        $this->get(FinancialDashboard::getUrl())->assertForbidden();
    }

    public function test_financial_dashboard_renders_for_permitted_user(): void
    {
        $admin = User::factory()->create([
            'type' => UserType::INTERNAL,
            'status' => 'active',
        ]);
        Gate::before(fn (User $u) => $u->id === $admin->id ? true : null);
        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');

        $this->get(FinancialDashboard::getUrl())->assertOk();
    }

    public function test_landing_redirects_to_financial_when_preferred(): void
    {
        $admin = User::factory()->create([
            'type' => UserType::INTERNAL,
            'status' => 'active',
            'default_dashboard' => 'financial',
        ]);
        Gate::before(fn (User $u) => $u->id === $admin->id ? true : null);
        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');

        $this->get(OperationalDashboard::getUrl())
            ->assertRedirect(FinancialDashboard::getUrl());

        // Second visit: preference applied this session, no redirect loop.
        $this->get(OperationalDashboard::getUrl())->assertOk();
    }

    public function test_visiting_financial_first_prevents_operational_bounce_back(): void
    {
        $admin = User::factory()->create([
            'type' => UserType::INTERNAL,
            'status' => 'active',
            'default_dashboard' => 'financial',
        ]);
        Gate::before(fn (User $u) => $u->id === $admin->id ? true : null);
        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');

        // First hit financial directly, then operational must NOT redirect.
        $this->get(FinancialDashboard::getUrl())->assertOk();
        $this->get(OperationalDashboard::getUrl())->assertOk();
    }

    public function test_switcher_tabs_visible_for_permitted_user(): void
    {
        $admin = User::factory()->create([
            'type' => UserType::INTERNAL,
            'status' => 'active',
        ]);
        Gate::before(fn (User $u) => $u->id === $admin->id ? true : null);
        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');

        $response = $this->get(OperationalDashboard::getUrl());
        $response->assertOk();
        $response->assertSee(__('navigation.pages.operational_dashboard'));
        $response->assertSee(__('navigation.pages.financial_dashboard'));
    }

    /**
     * NOTE: this is a separate test method (rather than a second act in the
     * test above) on purpose. Filament caches built navigation per process;
     * asserting as a second user within the same test method would see the
     * first user's (cached) navigation instead of being recomputed, giving a
     * false negative/positive unrelated to the code under test. Each test
     * method gets its own fresh application boot, avoiding that cache.
     */
    public function test_switcher_tabs_hidden_for_unpermitted_user(): void
    {
        $plainUser = User::factory()->create([
            'type' => UserType::INTERNAL,
            'status' => 'active',
        ]);
        $this->actingAs($plainUser);
        Filament::setCurrentPanel('admin');

        $response = $this->get(OperationalDashboard::getUrl());
        $response->assertOk();
        $response->assertDontSee(__('navigation.pages.financial_dashboard'));
    }
}
