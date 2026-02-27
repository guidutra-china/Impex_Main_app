<?php

namespace App\Filament\Portal\Resources\ShipmentResource\Widgets;

use App\Domain\Infrastructure\Support\Money;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;

class PortalShipmentOverview extends Widget
{
    protected static bool $isLazy = false;

    protected string $view = 'portal.widgets.shipment-overview';

    protected int|string|array $columnSpan = 'full';

    public ?Model $record = null;

    protected function getViewData(): array
    {
        if (! $this->record instanceof Shipment) {
            return ['cards' => [], 'timeline' => [], 'documents' => []];
        }

        $shipment = $this->record;
        $shipment->loadMissing(['items.proformaInvoiceItem.proformaInvoice', 'documents']);

        $currency = $shipment->currency_code ?? 'USD';

        $cards = [
            [
                'label' => __('widgets.portal.status'),
                'value' => $shipment->status->getLabel(),
                'icon' => $shipment->status->getIcon() ?? 'heroicon-o-information-circle',
                'color' => is_string($shipment->status->getColor()) ? $shipment->status->getColor() : 'gray',
            ],
            [
                'label' => __('widgets.portal.transport'),
                'value' => $shipment->transport_mode?->getLabel() ?? '—',
                'icon' => 'heroicon-o-truck',
                'color' => 'info',
            ],
            [
                'label' => __('widgets.portal.container'),
                'value' => $shipment->container_number ?: '—',
                'icon' => 'heroicon-o-cube',
                'color' => 'gray',
            ],
        ];

        if (auth()->user()?->can('portal:view-financial-summary')) {
            $cards[] = [
                'label' => __('widgets.portal.total_value'),
                'value' => $currency . ' ' . Money::format($shipment->total_value),
                'icon' => 'heroicon-o-currency-dollar',
                'color' => 'primary',
            ];
        }

        $timeline = [];
        if ($shipment->etd) {
            $timeline[] = [
                'label' => __('widgets.portal.etd'),
                'date' => $shipment->etd->format('M d, Y'),
                'actual' => $shipment->actual_departure?->format('M d, Y'),
                'icon' => 'heroicon-o-arrow-up-tray',
                'completed' => $shipment->actual_departure !== null,
            ];
        }
        if ($shipment->eta) {
            $timeline[] = [
                'label' => __('widgets.portal.eta'),
                'date' => $shipment->eta->format('M d, Y'),
                'actual' => $shipment->actual_arrival?->format('M d, Y'),
                'icon' => 'heroicon-o-arrow-down-tray',
                'completed' => $shipment->actual_arrival !== null,
            ];
        }

        $documents = $shipment->documents
            ->map(fn ($doc) => [
                'id' => $doc->id,
                'name' => $doc->name ?? __('widgets.portal.document'),
                'type' => is_object($doc->type) ? $doc->type->getLabel() : ($doc->type ?? __('widgets.portal.document')),
                'created_at' => $doc->created_at?->format('M d, Y'),
                'download_url' => route('portal.documents.download', $doc),
            ])
            ->all();

        $logistics = [
            ['label' => __('widgets.portal.origin_port'), 'value' => $shipment->origin_port ?: '—'],
            ['label' => __('widgets.portal.destination_port'), 'value' => $shipment->destination_port ?: '—'],
            ['label' => __('widgets.portal.carrier'), 'value' => $shipment->carrier ?: '—'],
            ['label' => __('widgets.portal.vessel'), 'value' => $shipment->vessel_name ?: '—'],
            ['label' => __('widgets.portal.bl_number'), 'value' => $shipment->bl_number ?: '—'],
            ['label' => __('widgets.portal.booking_number'), 'value' => $shipment->booking_number ?: '—'],
        ];

        $piRefs = $shipment->proforma_invoice_references;

        return [
            'cards' => $cards,
            'timeline' => $timeline,
            'logistics' => $logistics,
            'documents' => $documents,
            'piReferences' => $piRefs ?: '—',
            'totalPackages' => $shipment->total_packages ?? 0,
            'totalGrossWeight' => $shipment->total_gross_weight ? number_format($shipment->total_gross_weight, 2) . ' kg' : '—',
            'totalVolume' => $shipment->total_volume ? number_format($shipment->total_volume, 3) . ' m³' : '—',
        ];
    }
}
