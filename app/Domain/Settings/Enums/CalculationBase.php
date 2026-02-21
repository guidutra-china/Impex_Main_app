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

    public function getLabel(): ?string
    {
        return match ($this) {
            self::ORDER_DATE => 'Order Date',
            self::INVOICE_DATE => 'Invoice Date',
            self::SHIPMENT_DATE => 'Shipment Date',
            self::DELIVERY_DATE => 'Delivery Date',
            self::BL_DATE => 'Bill of Lading Date',
            self::PO_DATE => 'Purchase Order Date',
        };
    }
}
