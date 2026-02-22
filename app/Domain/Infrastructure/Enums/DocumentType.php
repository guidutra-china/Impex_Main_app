<?php

namespace App\Domain\Infrastructure\Enums;

enum DocumentType: string
{
    case INQUIRY = 'INQ';
    case SUPPLIER_QUOTATION = 'SQ';
    case QUOTATION = 'QT';
    case PROFORMA_INVOICE = 'PI';
    case PURCHASE_ORDER = 'PO';
    case SHIPMENT = 'SH';
    case CLIENT_INVOICE = 'CI';
    case PAYMENT = 'PAY';

    public function padLength(): int
    {
        return match ($this) {
            self::PAYMENT => 6,
            default => 5,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::INQUIRY => 'Inquiry',
            self::SUPPLIER_QUOTATION => 'Supplier Quotation',
            self::QUOTATION => 'Quotation',
            self::PROFORMA_INVOICE => 'Proforma Invoice',
            self::PURCHASE_ORDER => 'Purchase Order',
            self::SHIPMENT => 'Shipment',
            self::CLIENT_INVOICE => 'Client Invoice',
            self::PAYMENT => 'Payment',
        };
    }
}
