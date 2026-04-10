<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inquiries\Enums\InquiryStatus;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\PurchaseOrders\Enums\PurchaseOrderStatus;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus;
use Illuminate\Support\Collection;

class ProductDeletionGuard
{
    /**
     * Check if a product can be safely deleted.
     *
     * @return Collection<int, string> Blocking references (empty = safe to delete)
     */
    public function check(Product $product): Collection
    {
        $blocking = collect();

        $this->checkProformaInvoices($product, $blocking);
        $this->checkShipments($product, $blocking);
        $this->checkPurchaseOrders($product, $blocking);
        $this->checkQuotations($product, $blocking);
        $this->checkInquiries($product, $blocking);
        $this->checkSupplierQuotations($product, $blocking);

        return $blocking;
    }

    private function checkProformaInvoices(Product $product, Collection $blocking): void
    {
        $items = $product->proformaInvoiceItems()
            ->whereHas('proformaInvoice', fn ($q) => $q->whereNotIn('status', [
                ProformaInvoiceStatus::CANCELLED,
                ProformaInvoiceStatus::FINALIZED,
            ]))
            ->with('proformaInvoice')
            ->get();

        foreach ($items as $item) {
            $pi = $item->proformaInvoice;
            $blocking->push("PI {$pi->reference} ({$pi->status->getEnglishLabel()})");
        }
    }

    private function checkShipments(Product $product, Collection $blocking): void
    {
        $items = $product->proformaInvoiceItems()
            ->whereHas('shipmentItems.shipment', fn ($q) => $q->where('status', '!=', ShipmentStatus::CANCELLED))
            ->with(['shipmentItems.shipment'])
            ->get();

        foreach ($items as $piItem) {
            foreach ($piItem->shipmentItems as $shipmentItem) {
                $shipment = $shipmentItem->shipment;
                if ($shipment && $shipment->status !== ShipmentStatus::CANCELLED) {
                    $blocking->push("Shipment {$shipment->reference} ({$shipment->status->getEnglishLabel()})");
                }
            }
        }
    }

    private function checkPurchaseOrders(Product $product, Collection $blocking): void
    {
        $items = $product->purchaseOrderItems()
            ->whereHas('purchaseOrder', fn ($q) => $q->whereNotIn('status', [
                PurchaseOrderStatus::CANCELLED,
                PurchaseOrderStatus::COMPLETED,
            ]))
            ->with('purchaseOrder')
            ->get();

        foreach ($items as $item) {
            $po = $item->purchaseOrder;
            $blocking->push("PO {$po->reference} ({$po->status->getEnglishLabel()})");
        }
    }

    private function checkQuotations(Product $product, Collection $blocking): void
    {
        $items = $product->quotationItems()
            ->whereHas('quotation', fn ($q) => $q->whereNotIn('status', [
                QuotationStatus::REJECTED,
                QuotationStatus::EXPIRED,
                QuotationStatus::CANCELLED,
            ]))
            ->with('quotation')
            ->get();

        foreach ($items as $item) {
            $quotation = $item->quotation;
            $blocking->push("Quotation {$quotation->reference} ({$quotation->status->getEnglishLabel()})");
        }
    }

    private function checkInquiries(Product $product, Collection $blocking): void
    {
        $items = $product->inquiryItems()
            ->whereHas('inquiry', fn ($q) => $q->whereNotIn('status', [
                InquiryStatus::CANCELLED,
                InquiryStatus::WON,
                InquiryStatus::LOST,
            ]))
            ->with('inquiry')
            ->get();

        foreach ($items as $item) {
            $inquiry = $item->inquiry;
            $blocking->push("Inquiry {$inquiry->reference} ({$inquiry->status->getEnglishLabel()})");
        }
    }

    private function checkSupplierQuotations(Product $product, Collection $blocking): void
    {
        $items = $product->supplierQuotationItems()
            ->whereHas('supplierQuotation', fn ($q) => $q->whereNotIn('status', [
                SupplierQuotationStatus::REJECTED,
                SupplierQuotationStatus::EXPIRED,
            ]))
            ->with('supplierQuotation')
            ->get();

        foreach ($items as $item) {
            $sq = $item->supplierQuotation;
            $blocking->push("SQ {$sq->reference} ({$sq->status->getEnglishLabel()})");
        }
    }
}
