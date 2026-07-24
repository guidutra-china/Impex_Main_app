<?php

namespace App\Filament\Portal\Resources\ShipmentResource\Widgets;

use App\Domain\Financial\Services\ShipmentPaymentSummaryService;
use App\Domain\Logistics\Models\Shipment;
use App\Filament\Concerns\PresentsShipmentPaymentSummary;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;

class PortalShipmentPaymentProgress extends Widget
{
    use PresentsShipmentPaymentSummary;

    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.shipment-payment-progress';

    protected int|string|array $columnSpan = 'full';

    public ?Model $record = null;

    protected function getViewData(): array
    {
        /** @var Shipment $shipment */
        $shipment = $this->record;

        return [
            'blocks' => array_values(array_filter([
                $this->buildPaymentSummaryBlock(
                    __('messages.shipment_payments_client_title'),
                    app(ShipmentPaymentSummaryService::class)->forClient($shipment),
                ),
            ])),
        ];
    }
}
