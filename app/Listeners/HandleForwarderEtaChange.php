<?php

namespace App\Listeners;

use App\Domain\Logistics\Models\Shipment;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Services\MessagingService;
use App\Domain\Users\Enums\UserType;
use App\Events\ShipmentEtaChangedByForwarder;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HandleForwarderEtaChange
{
    public function __construct(private readonly MessagingService $messaging) {}

    public function handle(ShipmentEtaChangedByForwarder $event): void
    {
        $shipment = $event->shipment;

        DB::transaction(function () use ($shipment, $event) {
            $shipment->forceFill([
                'eta_change_pending_recalc' => true,
                'eta_changed_at' => now(),
                'eta_changed_by' => $event->userId,
            ])->save();
        });

        $oldLabel = $event->previousEta ?: '—';
        $newLabel = $event->newEta ?: '—';

        $responsible = $shipment->responsible;
        if ($responsible instanceof User) {
            Notification::make()
                ->title(__('forwarder_portal.notify.eta_changed_title', ['ref' => $shipment->reference]))
                ->body(__('forwarder_portal.notify.eta_changed_body', [
                    'old' => $oldLabel,
                    'new' => $newLabel,
                ]))
                ->warning()
                ->sendToDatabase($responsible);

            $this->postMessagingThread($shipment, $event, $responsible, $oldLabel, $newLabel);
        }

        $this->notifyClientUsers($shipment, $oldLabel, $newLabel);
    }

    /**
     * Push a Filament database notification to every active client user
     * attached to the shipment's company. Lights the bell icon on /portal
     * for all of them — they don't all need to act, just be aware.
     */
    protected function notifyClientUsers(Shipment $shipment, string $oldLabel, string $newLabel): void
    {
        $clientUsers = User::query()
            ->where('company_id', $shipment->company_id)
            ->where('type', UserType::CLIENT)
            ->where('status', 'active')
            ->get();

        if ($clientUsers->isEmpty()) {
            return;
        }

        $notification = Notification::make()
            ->title(__('forwarder_portal.notify.client_eta_changed_title', ['ref' => $shipment->reference]))
            ->body(__('forwarder_portal.notify.client_eta_changed_body', [
                'old' => $oldLabel,
                'new' => $newLabel,
            ]))
            ->warning();

        foreach ($clientUsers as $user) {
            $notification->sendToDatabase($user);
        }
    }

    protected function postMessagingThread(
        Shipment $shipment,
        ShipmentEtaChangedByForwarder $event,
        User $responsible,
        string $oldLabel,
        string $newLabel,
    ): void {
        $sender = $event->userId ? User::find($event->userId) : null;
        if (! $sender) {
            return;
        }

        try {
            $conversation = Conversation::query()
                ->where('subject_entity_type', Shipment::class)
                ->where('subject_entity_id', $shipment->getKey())
                ->whereHas('participants', fn ($q) => $q->where('user_id', $sender->id)->whereNull('left_at'))
                ->whereHas('participants', fn ($q) => $q->where('user_id', $responsible->id)->whereNull('left_at'))
                ->first();

            if (! $conversation) {
                $conversation = $this->messaging->createConversation(
                    creator: $sender,
                    participantUserIds: [$responsible->id],
                    subject: __('forwarder_portal.messaging.subject', ['ref' => $shipment->reference]),
                    type: 'direct',
                    subjectEntity: $shipment,
                );
            }

            $body = __('forwarder_portal.messaging.eta_change_body', [
                'old' => $oldLabel,
                'new' => $newLabel,
            ]);

            $this->messaging->sendMessage($conversation, $sender, $body);
        } catch (\Throwable $e) {
            // Messaging failure must not roll back the ETA change or notification.
            Log::warning('Forwarder ETA change messaging failed: '.$e->getMessage(), [
                'shipment_id' => $shipment->getKey(),
            ]);
        }
    }
}
