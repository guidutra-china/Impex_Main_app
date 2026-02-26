<?php

namespace App\Filament\Pages;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Inquiries\Enums\InquiryStatus;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Filament\Resources\Inquiries\InquiryResource;
use App\Filament\Resources\ProformaInvoices\ProformaInvoiceResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\Shipments\ShipmentResource;
use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;

class OrderPipelineKanban extends Page
{
    protected string $view = 'filament.pages.order-pipeline-kanban';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-view-columns';

    protected static UnitEnum|string|null $navigationGroup = 'Trade';

    protected static ?string $navigationLabel = 'Order Pipeline';

    protected static ?string $title = 'Order Pipeline';

    protected static ?int $navigationSort = 0;

    protected static ?string $slug = 'order-pipeline';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-order-pipeline') ?? false;
    }

    public function getColumns(): array
    {
        return [
            $this->buildInquiryColumn(),
            $this->buildQuotingColumn(),
            $this->buildPiIssuedColumn(),
            $this->buildInProductionColumn(),
            $this->buildShippingColumn(),
            $this->buildDeliveredColumn(),
        ];
    }

    protected function buildInquiryColumn(): array
    {
        $inquiries = Inquiry::query()
            ->with(['company', 'items'])
            ->whereIn('status', [InquiryStatus::RECEIVED, InquiryStatus::QUOTING])
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        return [
            'id' => 'inquiry',
            'title' => 'Inquiry',
            'color' => 'gray',
            'count' => $inquiries->count(),
            'cards' => $inquiries->map(fn (Inquiry $inquiry) => [
                'id' => $inquiry->id,
                'reference' => $inquiry->reference,
                'url' => InquiryResource::getUrl('view', ['record' => $inquiry]),
                'company' => $inquiry->company?->name ?? '—',
                'status' => $inquiry->status->value,
                'value' => null,
                'payment_progress' => null,
                'days_open' => $inquiry->created_at->diffInDays(now()),
                'items_count' => $inquiry->items->count(),
                'subtitle' => $inquiry->items->count() . ' item(s)',
            ])->toArray(),
        ];
    }

    protected function buildQuotingColumn(): array
    {
        $inquiries = Inquiry::query()
            ->with(['company', 'items'])
            ->where('status', InquiryStatus::QUOTED)
            ->orderBy('updated_at', 'desc')
            ->limit(50)
            ->get();

        return [
            'id' => 'quoting',
            'title' => 'Quoted',
            'color' => 'info',
            'count' => $inquiries->count(),
            'cards' => $inquiries->map(fn (Inquiry $inquiry) => [
                'id' => $inquiry->id,
                'reference' => $inquiry->reference,
                'url' => InquiryResource::getUrl('view', ['record' => $inquiry]),
                'company' => $inquiry->company?->name ?? '—',
                'status' => $inquiry->status->value,
                'value' => null,
                'payment_progress' => null,
                'days_open' => $inquiry->created_at->diffInDays(now()),
                'items_count' => $inquiry->items->count(),
                'subtitle' => 'Awaiting client decision',
            ])->toArray(),
        ];
    }

    protected function buildPiIssuedColumn(): array
    {
        $pis = ProformaInvoice::query()
            ->with(['company', 'items', 'paymentScheduleItems'])
            ->whereIn('status', [
                ProformaInvoiceStatus::DRAFT,
                ProformaInvoiceStatus::SENT,
                ProformaInvoiceStatus::CONFIRMED,
                ProformaInvoiceStatus::FINALIZED,
                ProformaInvoiceStatus::REOPENED,
            ])
            ->orderBy('updated_at', 'desc')
            ->limit(50)
            ->get();

        return [
            'id' => 'pi_issued',
            'title' => 'PI Issued',
            'color' => 'primary',
            'count' => $pis->count(),
            'cards' => $pis->map(fn (ProformaInvoice $pi) => [
                'id' => $pi->id,
                'reference' => $pi->reference,
                'url' => ProformaInvoiceResource::getUrl('view', ['record' => $pi]),
                'company' => $pi->company?->name ?? '—',
                'status' => $pi->status->value,
                'value' => Money::formatDisplay($pi->grand_total),
                'payment_progress' => $pi->payment_progress,
                'days_open' => $pi->created_at->diffInDays(now()),
                'items_count' => $pi->items->count(),
                'subtitle' => $pi->status->getLabel(),
                'has_overdue' => $pi->paymentScheduleItems
                    ->contains(fn ($item) => $item->status === PaymentScheduleStatus::OVERDUE),
            ])->toArray(),
        ];
    }

    protected function buildInProductionColumn(): array
    {
        $pos = PurchaseOrder::query()
            ->with(['supplierCompany', 'items', 'paymentScheduleItems', 'proformaInvoice'])
            ->whereIn('status', [
                PurchaseOrderStatus::CONFIRMED,
                PurchaseOrderStatus::IN_PRODUCTION,
            ])
            ->orderBy('updated_at', 'desc')
            ->limit(50)
            ->get();

        return [
            'id' => 'in_production',
            'title' => 'In Production',
            'color' => 'warning',
            'count' => $pos->count(),
            'cards' => $pos->map(fn (PurchaseOrder $po) => [
                'id' => $po->id,
                'reference' => $po->reference,
                'url' => PurchaseOrderResource::getUrl('view', ['record' => $po]),
                'company' => $po->supplierCompany?->name ?? '—',
                'status' => $po->status->value,
                'value' => Money::formatDisplay($po->total),
                'payment_progress' => $po->payment_progress,
                'days_open' => $po->created_at->diffInDays(now()),
                'items_count' => $po->items->count(),
                'subtitle' => $po->proformaInvoice?->reference ?? '—',
                'has_overdue' => $po->paymentScheduleItems
                    ->contains(fn ($item) => $item->status === PaymentScheduleStatus::OVERDUE),
                'days_since_update' => $po->updated_at->diffInDays(now()),
            ])->toArray(),
        ];
    }

    protected function buildShippingColumn(): array
    {
        $shipments = Shipment::query()
            ->with(['company', 'items.purchaseOrderItem.purchaseOrder'])
            ->whereIn('status', [
                ShipmentStatus::BOOKED,
                ShipmentStatus::CUSTOMS,
                ShipmentStatus::IN_TRANSIT,
            ])
            ->orderBy('updated_at', 'desc')
            ->limit(50)
            ->get();

        return [
            'id' => 'shipping',
            'title' => 'Shipping',
            'color' => 'success',
            'count' => $shipments->count(),
            'cards' => $shipments->map(fn (Shipment $shipment) => [
                'id' => $shipment->id,
                'reference' => $shipment->reference,
                'url' => ShipmentResource::getUrl('view', ['record' => $shipment]),
                'company' => $shipment->company?->name ?? '—',
                'status' => $shipment->status->value,
                'value' => Money::formatDisplay($shipment->total_value),
                'payment_progress' => null,
                'days_open' => $shipment->created_at->diffInDays(now()),
                'items_count' => $shipment->total_items_count,
                'subtitle' => $shipment->status->getLabel(),
                'eta' => $shipment->eta?->format('d/m/Y'),
            ])->toArray(),
        ];
    }

    protected function buildDeliveredColumn(): array
    {
        $shipments = Shipment::query()
            ->with(['company', 'items'])
            ->where('status', ShipmentStatus::ARRIVED)
            ->where('updated_at', '>=', now()->subDays(30))
            ->orderBy('updated_at', 'desc')
            ->limit(20)
            ->get();

        return [
            'id' => 'delivered',
            'title' => 'Delivered (30d)',
            'color' => 'gray',
            'count' => $shipments->count(),
            'cards' => $shipments->map(fn (Shipment $shipment) => [
                'id' => $shipment->id,
                'reference' => $shipment->reference,
                'url' => ShipmentResource::getUrl('view', ['record' => $shipment]),
                'company' => $shipment->company?->name ?? '—',
                'status' => 'arrived',
                'value' => Money::formatDisplay($shipment->total_value),
                'payment_progress' => null,
                'days_open' => null,
                'items_count' => $shipment->total_items_count,
                'subtitle' => 'Arrived ' . $shipment->updated_at->diffForHumans(),
            ])->toArray(),
        ];
    }
}
