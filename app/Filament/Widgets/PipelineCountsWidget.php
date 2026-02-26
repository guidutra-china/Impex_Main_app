<?php

namespace App\Filament\Widgets;

use App\Domain\Inquiries\Enums\InquiryStatus;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use Filament\Widgets\Widget;

class PipelineCountsWidget extends Widget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 2;

    protected string $view = 'filament.widgets.pipeline-counts';

    protected int | string | array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can('view-operational-dashboard') ?? false;
    }

    protected function getViewData(): array
    {
        $activeInquiries = Inquiry::query()
            ->whereIn('status', [InquiryStatus::RECEIVED, InquiryStatus::QUOTING, InquiryStatus::QUOTED])
            ->count();

        $activePIs = ProformaInvoice::query()
            ->whereIn('status', [
                ProformaInvoiceStatus::DRAFT,
                ProformaInvoiceStatus::SENT,
                ProformaInvoiceStatus::CONFIRMED,
                ProformaInvoiceStatus::FINALIZED,
                ProformaInvoiceStatus::REOPENED,
            ])
            ->count();

        $activePOs = PurchaseOrder::query()
            ->whereIn('status', [
                PurchaseOrderStatus::DRAFT,
                PurchaseOrderStatus::SENT,
                PurchaseOrderStatus::CONFIRMED,
                PurchaseOrderStatus::IN_PRODUCTION,
            ])
            ->count();

        $inProductionPOs = PurchaseOrder::query()
            ->where('status', PurchaseOrderStatus::IN_PRODUCTION)
            ->count();

        $activeShipments = Shipment::query()
            ->whereIn('status', [
                ShipmentStatus::BOOKED,
                ShipmentStatus::CUSTOMS,
                ShipmentStatus::IN_TRANSIT,
            ])
            ->count();

        $inTransit = Shipment::query()
            ->where('status', ShipmentStatus::IN_TRANSIT)
            ->count();

        $totalActive = $activeInquiries + $activePIs + $activePOs + $activeShipments;

        return [
            'stages' => [
                [
                    'label' => 'Inquiries',
                    'count' => $activeInquiries,
                    'detail' => 'Open client inquiries',
                    'icon' => 'heroicon-o-magnifying-glass',
                    'color' => 'info',
                    'url' => route('filament.admin.resources.inquiries.index'),
                ],
                [
                    'label' => 'Proforma Invoices',
                    'count' => $activePIs,
                    'detail' => 'In progress',
                    'icon' => 'heroicon-o-document-text',
                    'color' => 'primary',
                    'url' => route('filament.admin.resources.proforma-invoices.index'),
                ],
                [
                    'label' => 'Purchase Orders',
                    'count' => $activePOs,
                    'detail' => $inProductionPOs > 0 ? $inProductionPOs . ' in production' : 'In progress',
                    'icon' => 'heroicon-o-shopping-cart',
                    'color' => 'warning',
                    'url' => route('filament.admin.resources.purchase-orders.index'),
                ],
                [
                    'label' => 'Shipments',
                    'count' => $activeShipments,
                    'detail' => $inTransit > 0 ? $inTransit . ' in transit' : 'In progress',
                    'icon' => 'heroicon-o-truck',
                    'color' => 'success',
                    'url' => route('filament.admin.resources.shipments.index'),
                ],
            ],
            'totalActive' => $totalActive,
        ];
    }
}
