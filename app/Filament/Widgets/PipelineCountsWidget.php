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
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PipelineCountsWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        return auth()->user()?->can('view-operational-dashboard') ?? false;
    }

    protected function getStats(): array
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

        return [
            Stat::make('Active Inquiries', $activeInquiries)
                ->description('Open client inquiries')
                ->icon('heroicon-o-magnifying-glass')
                ->color($activeInquiries > 0 ? 'info' : 'gray'),

            Stat::make('Active PIs', $activePIs)
                ->description('Proforma invoices in progress')
                ->icon('heroicon-o-document-text')
                ->color($activePIs > 0 ? 'primary' : 'gray'),

            Stat::make('Active POs', $activePOs)
                ->description($inProductionPOs > 0 ? $inProductionPOs . ' in production' : 'Purchase orders in progress')
                ->icon('heroicon-o-shopping-cart')
                ->color($activePOs > 0 ? 'warning' : 'gray'),

            Stat::make('Active Shipments', $activeShipments)
                ->description($inTransit > 0 ? $inTransit . ' in transit' : 'Shipments in progress')
                ->icon('heroicon-o-truck')
                ->color($activeShipments > 0 ? 'success' : 'gray'),
        ];
    }
}
