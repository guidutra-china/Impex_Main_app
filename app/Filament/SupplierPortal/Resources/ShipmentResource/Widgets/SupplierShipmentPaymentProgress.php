<?php

namespace App\Filament\SupplierPortal\Resources\ShipmentResource\Widgets;

use App\Domain\Financial\Services\ShipmentPaymentSummaryService;
use App\Domain\Logistics\Models\Shipment;
use App\Filament\Concerns\PresentsShipmentPaymentSummary;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;

class SupplierShipmentPaymentProgress extends Widget
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
        $supplierCompanyId = auth()->user()?->company_id;

        if (! $supplierCompanyId) {
            return ['blocks' => []];
        }

        return [
            'blocks' => array_values(array_filter([
                $this->buildPaymentSummaryBlock(
                    __('messages.shipment_payments_supplier_own_title'),
                    app(ShipmentPaymentSummaryService::class)->forSupplier($shipment, (int) $supplierCompanyId),
                ),
            ])),
        ];
    }
}
