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
    case PRODUCTION_SCHEDULE = 'PS';
    case SHIPMENT_PLAN = 'SP';
    case DEBIT_NOTE = 'DN';
    case CREDIT_NOTE = 'CN';

    public function padLength(): int
    {
        return match ($this) {
            self::PAYMENT => 6,
            // DN/CN nasceram com 4 dígitos (DN-2026-0001) — manter o formato.
            self::DEBIT_NOTE, self::CREDIT_NOTE => 4,
            default => 5,
        };
    }

    public function label(): string
    {
        return __('enums.document_type.'.$this->value);
    }

    public function englishLabel(): string
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
            self::PRODUCTION_SCHEDULE => 'Production Schedule',
            self::SHIPMENT_PLAN => 'Shipment Plan',
            self::DEBIT_NOTE => 'Debit Note',
            self::CREDIT_NOTE => 'Credit Note',
        };
    }
}
