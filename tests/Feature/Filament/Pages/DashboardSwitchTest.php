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
}
