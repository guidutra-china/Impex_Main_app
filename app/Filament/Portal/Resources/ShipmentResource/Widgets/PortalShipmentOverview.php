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
                'label' => 'Status',
                'value' => $shipment->status->getLabel(),
                'icon' => $shipment->status->getIcon() ?? 'heroicon-o-information-circle',
                'color' => is_string($shipment->status->getColor()) ? $shipment->status->getColor() : 'gray',
            ],
            [
                'label' => 'Transport',
                'value' => $shipment->transport_mode?->getLabel() ?? '—',
                'icon' => 'heroicon-o-truck',
                'color' => 'info',
            ],
            [
                'label' => 'Container',
                'value' => $shipment->container_number ?: '—',
                'icon' => 'heroicon-o-cube',
                'color' => 'gray',
            ],
        ];

        if (auth()->user()?->can('portal:view-financial-summary')) {
            $cards[] = [
                'label' => 'Total Value',
                'value' => $currency . ' ' . Money::format($shipment->total_value),
                'icon' => 'heroicon-o-currency-dollar',
                'color' => 'primary',
            ];
        }

        $timeline = [];
        if ($shipment->etd) {
            $timeline[] = [
                'label' => 'ETD',
                'date' => $shipment->etd->format('M d, Y'),
                'actual' => $shipment->actual_departure?->format('M d, Y'),
                'icon' => 'heroicon-o-arrow-up-tray',
                'completed' => $shipment->actual_departure !== null,
            ];
        }
        if ($shipment->eta) {
            $timeline[] = [
                'label' => 'ETA',
                'date' => $shipment->eta->format('M d, Y'),
                'actual' => $shipment->actual_arrival?->format('M d, Y'),
                'icon' => 'heroicon-o-arrow-down-tray',
                'completed' => $shipment->actual_arrival !== null,
            ];
        }

        $documents = $shipment->documents
            ->map(fn ($doc) => [
                'id' => $doc->id,
                'name' => $doc->name ?? 'Document',
                'type' => is_object($doc->type) ? $doc->type->getLabel() : ($doc->type ?? 'Document'),
                'created_at' => $doc->created_at?->format('M d, Y'),
                'download_url' => route('portal.documents.download', $doc),
            ])
            ->all();

        $logistics = [
            ['label' => 'Origin Port', 'value' => $shipment->origin_port ?: '—'],
            ['label' => 'Destination Port', 'value' => $shipment->destination_port ?: '—'],
            ['label' => 'Carrier', 'value' => $shipment->carrier ?: '—'],
            ['label' => 'Vessel', 'value' => $shipment->vessel_name ?: '—'],
            ['label' => 'BL Number', 'value' => $shipment->bl_number ?: '—'],
            ['label' => 'Booking Number', 'value' => $shipment->booking_number ?: '—'],
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
