<?php

namespace App\Providers;

use App\Domain\Inquiries\Models\ProjectTeamMember;
use App\Domain\Inquiries\Observers\ProjectTeamMemberObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ProjectTeamMember::observe(ProjectTeamMemberObserver::class);
    }
}
