<?php

namespace App\Domain\Infrastructure\Pdf\Templates;

use App\Domain\Infrastructure\Pdf\DocumentLabels;

/**
 * Shipment-level Proforma Invoice. Identical content to the Commercial Invoice
 * (same data, layout and view) — only the title, document type and filename
 * prefix differ. Used when the shipment needs to issue a draft/pre-shipment
 * invoice for advance payment or customs purposes.
 */
class ShipmentProformaInvoicePdfTemplate extends CommercialInvoicePdfTemplate
{
    public function getDocumentTitle(): string
    {
        return DocumentLabels::get('proforma_invoice', $this->locale);
    }

    public function getDocumentType(): string
    {
        return 'shipment_proforma_invoice_pdf';
    }

    public function getFilename(): string
    {
        $reference = $this->model->reference ?? $this->model->getKey();

        return 'PI-' . $reference . '-v' . $this->getNextVersion() . '.pdf';
    }
}
