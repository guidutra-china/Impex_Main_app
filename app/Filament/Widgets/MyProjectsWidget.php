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
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use Filament\Widgets\Widget;

class MyProjectsWidget extends Widget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 3;

    protected string $view = 'filament.widgets.my-projects';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->check();
    }

    protected function getViewData(): array
    {
        $userId = auth()->id();

        $myInquiries = Inquiry::query()
            ->where('responsible_user_id', $userId)
            ->whereNotIn('status', [InquiryStatus::CANCELLED, InquiryStatus::LOST])
            ->latest('updated_at')
            ->limit(10)
            ->get();

        $counts = [
            'inquiries' => Inquiry::where('responsible_user_id', $userId)
                ->whereNotIn('status', [InquiryStatus::CANCELLED, InquiryStatus::LOST, InquiryStatus::WON])
                ->count(),
            'quotations' => Quotation::where('responsible_user_id', $userId)
                ->whereNotIn('status', [QuotationStatus::CANCELLED, QuotationStatus::REJECTED])
                ->count(),
            'supplier_quotations' => SupplierQuotation::where('responsible_user_id', $userId)
                ->whereNotIn('status', [SupplierQuotationStatus::CANCELLED, SupplierQuotationStatus::REJECTED])
                ->count(),
            'proforma_invoices' => ProformaInvoice::where('responsible_user_id', $userId)
                ->whereNotIn('status', [ProformaInvoiceStatus::CANCELLED])
                ->count(),
            'purchase_orders' => PurchaseOrder::where('responsible_user_id', $userId)
                ->whereNotIn('status', [PurchaseOrderStatus::CANCELLED])
                ->count(),
            'shipments' => Shipment::where('responsible_user_id', $userId)
                ->whereNotIn('status', [ShipmentStatus::CANCELLED])
                ->count(),
        ];

        $totalActive = array_sum($counts);

        $urgentItems = [];

        $stalledInquiries = Inquiry::where('responsible_user_id', $userId)
            ->whereIn('status', [InquiryStatus::RECEIVED, InquiryStatus::QUOTING])
            ->where('updated_at', '<', now()->subDays(5))
            ->count();

        if ($stalledInquiries > 0) {
            $urgentItems[] = [
                'type' => 'warning',
                'icon' => 'heroicon-o-magnifying-glass',
                'text' => $stalledInquiries . ' ' . __('widgets.my_projects.inquiries_no_update_5_days'),
                'url' => route('filament.admin.resources.inquiries.index'),
            ];
        }

        $stalledPOs = PurchaseOrder::where('responsible_user_id', $userId)
            ->whereIn('status', [PurchaseOrderStatus::CONFIRMED, PurchaseOrderStatus::IN_PRODUCTION])
            ->where('updated_at', '<', now()->subDays(10))
            ->count();

        if ($stalledPOs > 0) {
            $urgentItems[] = [
                'type' => 'warning',
                'icon' => 'heroicon-o-shopping-cart',
                'text' => $stalledPOs . ' ' . __('widgets.my_projects.pos_no_update_10_days'),
                'url' => route('filament.admin.resources.purchase-orders.index'),
            ];
        }

        $activePIs = ProformaInvoice::where('responsible_user_id', $userId)
            ->where('status', ProformaInvoiceStatus::FINALIZED)
            ->whereDoesntHave('purchaseOrders')
            ->count();

        if ($activePIs > 0) {
            $urgentItems[] = [
                'type' => 'info',
                'icon' => 'heroicon-o-document-text',
                'text' => $activePIs . ' ' . __('widgets.my_projects.finalized_pis_without_po'),
                'url' => route('filament.admin.resources.proforma-invoices.index'),
            ];
        }

        return [
            'myInquiries' => $myInquiries,
            'counts' => $counts,
            'totalActive' => $totalActive,
            'urgentItems' => $urgentItems,
        ];
    }
}
