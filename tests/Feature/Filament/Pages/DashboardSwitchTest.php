<?php

namespace Tests\Feature\Filament\Pages;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
