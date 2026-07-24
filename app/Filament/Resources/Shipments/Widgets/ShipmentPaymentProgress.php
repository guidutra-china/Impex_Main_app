<?php

namespace App\Filament\Resources\Shipments\Widgets;

use App\Domain\Financial\Services\ShipmentPaymentSummaryService;
use App\Domain\Logistics\Models\Shipment;
use App\Filament\Concerns\PresentsShipmentPaymentSummary;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;

class ShipmentPaymentProgress extends Widget
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
        $service = app(ShipmentPaymentSummaryService::class);

        return [
            'blocks' => array_values(array_filter([
                $this->buildPaymentSummaryBlock(
                    __('messages.shipment_payments_client_title'),
                    $service->forClient($shipment),
                ),
                $this->buildPaymentSummaryBlock(
                    __('messages.shipment_payments_supplier_title'),
                    $service->forSupplier($shipment),
                ),
            ])),
        ];
    }
}
