<?php

namespace App\Domain\Settings\Enums;

use Filament\Support\Contracts\HasLabel;

enum CalculationBase: string implements HasLabel
{
    case ORDER_DATE = 'order_date';
    case INVOICE_DATE = 'invoice_date';
    case SHIPMENT_DATE = 'shipment_date';
    case DELIVERY_DATE = 'delivery_date';
    case BL_DATE = 'bl_date';
    case PO_DATE = 'po_date';
    case BEFORE_SHIPMENT = 'before_shipment';
    case BEFORE_PRODUCTION = 'before_production';
    case AFTER_PRODUCTION = 'after_production';

    public function getLabel(): ?string
    {
        return __('enums.calculation_base.'.$this->value);
    }

    public function isShipmentDependent(): bool
    {
        return in_array($this, [
            self::BEFORE_SHIPMENT,
            self::SHIPMENT_DATE,
            self::DELIVERY_DATE,
            self::BL_DATE,
        ]);
    }

    /**
     * Stages charged once per document (PI/PO), not per shipment — the
     * complement of isShipmentDependent(). A document-level parcel is never
     * carved out per shipment, so a shipment that carries the document shows
     * the whole parcel rather than a slice of it.
     */
    public function isDocumentLevel(): bool
    {
        return ! $this->isShipmentDependent();
    }

    /**
     * Values of every document-level stage — for query filters.
     *
     * @return array<int, string>
     */
    public static function documentLevelValues(): array
    {
        return array_values(array_map(
            fn (self $case) => $case->value,
            array_filter(self::cases(), fn (self $case) => $case->isDocumentLevel()),
        ));
    }

    public function getEnglishLabel(): string
    {
        return match ($this) {
            self::ORDER_DATE => 'Order Date',
            self::INVOICE_DATE => 'Invoice Date',
            self::SHIPMENT_DATE => 'Shipment Date',
            self::DELIVERY_DATE => 'Delivery Date',
            self::BL_DATE => 'Bill of Lading Date',
            self::PO_DATE => 'Purchase Order Date',
            self::BEFORE_SHIPMENT => 'Before Shipment',
            self::BEFORE_PRODUCTION => 'Before Production',
            self::AFTER_PRODUCTION => 'After Production',
        };
    }
}
