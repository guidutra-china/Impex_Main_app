<?php

namespace App\Filament\ForwarderPortal\Resources\ShipmentResource\Pages;

use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use App\Events\ShipmentEtaChangedByForwarder;
use App\Filament\ForwarderPortal\Resources\ShipmentResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditShipment extends EditRecord
{
    protected static string $resource = ShipmentResource::class;

    protected ?string $previousEta = null;

    protected ?string $previousStatus = null;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->previousEta = $data['eta'] ?? null;
        $this->previousStatus = $data['status'] ?? null;

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Shipment $record */
        $record = $this->record;

        // Re-read originals from the DB so we don't trust round-tripped state.
        $this->previousEta = $record->getOriginal('eta')?->format('Y-m-d') ?? null;
        $this->previousStatus = $record->getOriginal('status') instanceof ShipmentStatus
            ? $record->getOriginal('status')->value
            : $record->getOriginal('status');

        $newStatus = $data['status'] ?? null;
        $statusChanged = $newStatus && $newStatus !== $this->previousStatus;

        if ($statusChanged) {
            $allowedForForwarder = [
                ShipmentStatus::IN_TRANSIT->value,
                ShipmentStatus::ARRIVED->value,
            ];
            if (! in_array($newStatus, $allowedForForwarder, true) || ! $record->canTransitionTo($newStatus)) {
                Notification::make()
                    ->danger()
                    ->title(__('forwarder_portal.shipment.invalid_transition'))
                    ->send();

                throw ValidationException::withMessages([
                    'data.status' => __('forwarder_portal.shipment.invalid_transition'),
                ]);
            }

            // Block ARRIVED if today is still earlier than the planned ETA.
            // Forwarder can revert to IN_TRANSIT later if cargo really did arrive
            // before the planned ETA — but in that case the ETA itself should be
            // corrected first.
            if ($newStatus === ShipmentStatus::ARRIVED->value) {
                $eta = $record->eta;
                if ($eta && now()->startOfDay()->lt($eta->copy()->startOfDay())) {
                    Notification::make()
                        ->danger()
                        ->title(__('forwarder_portal.shipment.cannot_arrive_before_eta_title'))
                        ->body(__('forwarder_portal.shipment.cannot_arrive_before_eta_body', [
                            'eta' => $eta->format('d/m/Y'),
                        ]))
                        ->send();

                    throw ValidationException::withMessages([
                        'data.status' => __('forwarder_portal.shipment.cannot_arrive_before_eta_title'),
                    ]);
                }
            }
        }

        // Auto-fill actual_arrival when transitioning to ARRIVED and the user
        // confirmed via the reactive toggle. Only applied if actual_arrival is
        // empty so we never overwrite a date the forwarder typed manually.
        $autoFill = (bool) ($data['set_actual_arrival_today'] ?? false);
        if ($statusChanged
            && $newStatus === ShipmentStatus::ARRIVED->value
            && $autoFill
            && empty($data['actual_arrival'])
        ) {
            $data['actual_arrival'] = now()->toDateString();
        }
        unset($data['set_actual_arrival_today']);

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var Shipment $record */
        $record = $this->record->refresh();

        $newEta = $record->eta?->format('Y-m-d');
        if ($this->previousEta !== $newEta) {
            event(new ShipmentEtaChangedByForwarder(
                shipment: $record,
                previousEta: $this->previousEta,
                newEta: $newEta,
                userId: auth()->id(),
            ));

            Notification::make()
                ->success()
                ->title(__('forwarder_portal.shipment.eta_change_acknowledged'))
                ->body(__('forwarder_portal.shipment.eta_change_acknowledged_body'))
                ->send();
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
