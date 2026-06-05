<?php

namespace App\Domain\Operations;

use App\Domain\Inquiries\Enums\InquiryStatus;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus;
use Illuminate\Database\Eloquent\Model;

final class OperationsPipeline
{
    /**
     * The end-to-end Operations pipeline, declared once. Single source of truth
     * for stage membership (consumed by OrderPipelineKanban).
     *
     * @return array<PipelineStage>
     */
    public static function stages(): array
    {
        return [
            new PipelineStage('inquiry', 'Inquiry', 'gray', Inquiry::class,
                [InquiryStatus::RECEIVED, InquiryStatus::QUOTING], ['company', 'items']),
            new PipelineStage('quoting', 'Quoted', 'info', Inquiry::class,
                [InquiryStatus::QUOTED], ['company', 'items']),
            new PipelineStage('pi_issued', 'PI Issued', 'primary', ProformaInvoice::class, [
                ProformaInvoiceStatus::DRAFT,
                ProformaInvoiceStatus::SENT,
                ProformaInvoiceStatus::CONFIRMED,
                ProformaInvoiceStatus::FINALIZED,
                ProformaInvoiceStatus::REOPENED,
            ], ['company', 'items', 'paymentScheduleItems']),
            new PipelineStage('in_production', 'In Production', 'warning', PurchaseOrder::class, [
                PurchaseOrderStatus::CONFIRMED,
                PurchaseOrderStatus::IN_PRODUCTION,
                PurchaseOrderStatus::AWAITING_SHIPMENT,
            ], ['supplierCompany', 'items', 'paymentScheduleItems', 'proformaInvoice']),
            new PipelineStage('shipping', 'Shipping', 'success', Shipment::class, [
                ShipmentStatus::BOOKED,
                ShipmentStatus::CUSTOMS,
                ShipmentStatus::IN_TRANSIT,
            ], ['company', 'items.purchaseOrderItem.purchaseOrder']),
            new PipelineStage('delivered', 'Delivered (30d)', 'gray', Shipment::class,
                [ShipmentStatus::ARRIVED], ['company', 'items']),
        ];
    }

    public static function stage(string $key): PipelineStage
    {
        foreach (self::stages() as $stage) {
            if ($stage->key === $key) {
                return $stage;
            }
        }

        throw new \InvalidArgumentException("Unknown pipeline stage: {$key}");
    }

    /**
     * Declarative lifecycle auto-advances applied by TransitionStatusAction
     * after a successful transition.
     *
     * @return array<AutoAdvance>
     */
    public static function autoAdvances(): array
    {
        return [
            // Confirming a Proforma Invoice marks its originating Inquiry as won.
            new AutoAdvance(
                ProformaInvoice::class,
                ProformaInvoiceStatus::CONFIRMED->value,
                fn (ProformaInvoice $pi) => $pi->inquiry,
                InquiryStatus::WON->value,
            ),

            // Confirming a Proforma Invoice selects all its linked supplier quotations
            // (canTransitionTo filters to those still in received/under_analysis).
            new AutoAdvance(
                ProformaInvoice::class,
                ProformaInvoiceStatus::CONFIRMED->value,
                fn (ProformaInvoice $pi) => $pi->supplierQuotations,
                SupplierQuotationStatus::SELECTED->value,
            ),
        ];
    }

    /**
     * @return array<AutoAdvance>
     */
    public static function autoAdvancesFor(Model $model, string $toStatus): array
    {
        return array_values(array_filter(
            self::autoAdvances(),
            fn (AutoAdvance $a) => $model instanceof $a->sourceModelClass && $a->sourceToStatus === $toStatus,
        ));
    }
}
