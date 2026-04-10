<?php

namespace App\Providers;

use App\Domain\Inquiries\Models\ProjectTeamMember;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Observers\PaymentAllocationObserver;
use App\Domain\Inquiries\Observers\ProjectTeamMemberObserver;
use App\Domain\Logistics\Models\PackingListItem;
use App\Domain\Logistics\Observers\PackingListItemObserver;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Policies\ConversationPolicy;
use App\Policies\MessagePolicy;
use Illuminate\Support\Facades\Gate;
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
        PackingListItem::observe(PackingListItemObserver::class);
        PaymentAllocation::observe(PaymentAllocationObserver::class);

        // Messaging policies live outside Filament Shield's auto-discovery.
        Gate::policy(Conversation::class, ConversationPolicy::class);
        Gate::policy(Message::class, MessagePolicy::class);
    }
}
