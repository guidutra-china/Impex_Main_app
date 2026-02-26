<?php

namespace App\Domain\SupplierQuotations\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SupplierQuotationStatus: string implements HasLabel, HasColor
{
    case REQUESTED = 'requested';
    case RECEIVED = 'received';
    case UNDER_ANALYSIS = 'under_analysis';
    case SELECTED = 'selected';
    case REJECTED = 'rejected';
    case EXPIRED = 'expired';

    public function getLabel(): ?string
    {
        return __('enums.supplier_quotation_status.' . $this->value);
    }


    public function getColor(): string
    {
        return match ($this) {
            self::REQUESTED => 'info',
            self::RECEIVED => 'primary',
            self::UNDER_ANALYSIS => 'warning',
            self::SELECTED => 'success',
            self::REJECTED => 'danger',
            self::EXPIRED => 'gray',
        };
    }

    public function getEnglishLabel(): string
    {
        return match ($this) {
            self::REQUESTED => 'Requested',
            self::RECEIVED => 'Received',
            self::UNDER_ANALYSIS => 'Under Analysis',
            self::SELECTED => 'Selected',
            self::REJECTED => 'Rejected',
            self::EXPIRED => 'Expired',
        };
    }
}
