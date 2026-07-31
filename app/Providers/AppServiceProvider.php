<?php

namespace App\Providers;

use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Observers\PaymentAllocationObserver;
use App\Domain\Financial\Support\OpenItemsSnapshot;
use App\Domain\Inquiries\Models\ProjectTeamMember;
use App\Domain\Inquiries\Observers\ProjectTeamMemberObserver;
use App\Domain\Logistics\Models\PackingListItem;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\Logistics\Observers\PackingListItemObserver;
use App\Domain\Logistics\Observers\ShipmentItemObserver;
use App\Domain\Logistics\Observers\ShipmentObserver;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Events\ShipmentEtaChangedByForwarder;
use App\Listeners\HandleForwarderEtaChange;
use App\Policies\ConversationPolicy;
use App\Policies\MessagePolicy;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Snapshot compartilhado dos itens em aberto: os widgets do dashboard
        // financeiro renderizados na mesma request dividem uma única carga.
        $this->app->scoped(OpenItemsSnapshot::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ProjectTeamMember::observe(ProjectTeamMemberObserver::class);
        PackingListItem::observe(PackingListItemObserver::class);
        PaymentAllocation::observe(PaymentAllocationObserver::class);
        Shipment::observe(ShipmentObserver::class);
        ShipmentItem::observe(ShipmentItemObserver::class);

        // Messaging policies live outside Filament Shield's auto-discovery.
        Gate::policy(Conversation::class, ConversationPolicy::class);
        Gate::policy(Message::class, MessagePolicy::class);

        Event::listen(ShipmentEtaChangedByForwarder::class, HandleForwarderEtaChange::class);

        // once() memoiza pelo tempo de vida do processo — em workers de fila
        // de longa duração isso vazaria cache (ex.: Currency::base()) entre
        // jobs. Cada job começa com o cache limpo.
        Queue::before(fn () => Once::flush());

        // Regra de negócio: cada usuário escolhe quantos itens por página quer ver
        // nas tabelas (menu do usuário no topo). Só aplica quando a opção existe
        // nas opções de paginação da tabela; caso contrário mantém o padrão dela.
        Table::configureUsing(function (Table $table): void {
            $table->defaultPaginationPageOption(function () use ($table): ?int {
                $perPage = auth()->user()?->records_per_page;

                if ($perPage && in_array($perPage, $table->getPaginationPageOptions(), true)) {
                    return $perPage;
                }

                return null;
            });
        });
    }
}
