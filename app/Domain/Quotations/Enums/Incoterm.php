<?php

namespace App\Domain\Quotations\Enums;

use Filament\Support\Contracts\HasLabel;

enum Incoterm: string implements HasLabel
{
    case EXW = 'EXW';
    case FCA = 'FCA';
    case FAS = 'FAS';
    case FOB = 'FOB';
    case CFR = 'CFR';
    case CIF = 'CIF';
    case CPT = 'CPT';
    case CIP = 'CIP';
    case DAP = 'DAP';
    case DPU = 'DPU';
    case DDP = 'DDP';

    public function getLabel(): ?string
    {
        return __('enums.incoterm.' . $this->value);
    }


    public function getEnglishLabel(): string
    {
        return match ($this) {
            self::EXW => 'EXW - Ex Works',
            self::FCA => 'FCA - Free Carrier',
            self::FAS => 'FAS - Free Alongside Ship',
            self::FOB => 'FOB - Free on Board',
            self::CFR => 'CFR - Cost and Freight',
            self::CIF => 'CIF - Cost, Insurance & Freight',
            self::CPT => 'CPT - Carriage Paid To',
            self::CIP => 'CIP - Carriage & Insurance Paid To',
            self::DAP => 'DAP - Delivered at Place',
            self::DPU => 'DPU - Delivered at Place Unloaded',
            self::DDP => 'DDP - Delivered Duty Paid',
        };
    }
}
