<?php

namespace App\Domain\Logistics\Observers;

use App\Domain\Financial\Actions\GeneratePaymentScheduleAction;
use App\Domain\Logistics\Actions\SyncFulfillmentStatusAction;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;

class ShipmentObserver
{
    public function __construct(
        private readonly SyncFulfillmentStatusAction $sync,
    ) {}

    /**
     * Excluir apaga as parcelas sem dinheiro do embarque (Shipment::deleting);
     * restaurar refaz o cronograma — o mesmo "Regenerar" da página. Draft e
     * cancelado não têm cronograma para refazer (mesmo recorte do comando
     * financial:regenerate-shipment-schedules).
     *
     * Resolvido na hora, e não no construtor, porque o observer é instanciado
     * no boot — antes de qualquer mock de teste.
     */
    public function restored(Shipment $shipment): void
    {
        if (in_array($shipment->status, [ShipmentStatus::DRAFT, ShipmentStatus::CANCELLED], true)) {
            return;
        }

        app(GeneratePaymentScheduleAction::class)->regenerateForShipment($shipment);
    }

    public function updated(Shipment $shipment): void
    {
        if (! $shipment->wasChanged('status')) {
            return;
        }

        $newStatus = $shipment->status;

        if ($newStatus instanceof ShipmentStatus
            && in_array($newStatus, [ShipmentStatus::IN_TRANSIT, ShipmentStatus::ARRIVED], true)) {
            $this->sync->execute($shipment);
        }
    }
}
