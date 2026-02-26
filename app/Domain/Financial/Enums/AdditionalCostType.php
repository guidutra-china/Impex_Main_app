<?php

namespace App\Domain\Financial\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum AdditionalCostType: string implements HasLabel, HasColor, HasIcon
{
    case TESTING = 'testing';
    case INSPECTION = 'inspection';
    case SAMPLES = 'samples';
    case SAMPLE_SHIPPING = 'sample_shipping';
    case FREIGHT = 'freight';
    case CUSTOMS = 'customs';
    case INSURANCE = 'insurance';
    case PACKAGING = 'packaging';
    case CERTIFICATION = 'certification';
    case TRAVEL = 'travel';
    case COMMISSION = 'commission';
    case OTHER = 'other';

    public function getLabel(): ?string
    {
        return __('enums.additional_cost_type.' . $this->value);
    }


    public function getColor(): string|array|null
    {
        return match ($this) {
            self::TESTING, self::INSPECTION, self::CERTIFICATION => 'info',
            self::SAMPLES, self::SAMPLE_SHIPPING => 'warning',
            self::FREIGHT, self::CUSTOMS, self::INSURANCE => 'primary',
            self::TRAVEL => 'gray',
            self::COMMISSION => 'success',
            self::PACKAGING => 'secondary',
            self::OTHER => 'gray',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::TESTING => 'heroicon-o-beaker',
            self::INSPECTION => 'heroicon-o-magnifying-glass',
            self::SAMPLES => 'heroicon-o-cube',
            self::SAMPLE_SHIPPING => 'heroicon-o-truck',
            self::FREIGHT => 'heroicon-o-globe-alt',
            self::CUSTOMS => 'heroicon-o-shield-check',
            self::INSURANCE => 'heroicon-o-shield-exclamation',
            self::PACKAGING => 'heroicon-o-archive-box',
            self::CERTIFICATION => 'heroicon-o-document-check',
            self::TRAVEL => 'heroicon-o-paper-airplane',
            self::COMMISSION => 'heroicon-o-currency-dollar',
            self::OTHER => 'heroicon-o-ellipsis-horizontal-circle',
        };
    }

    public function getEnglishLabel(): string
    {
        return match ($this) {
            self::TESTING => 'Lab Testing',
            self::INSPECTION => 'Inspection',
            self::SAMPLES => 'Samples',
            self::SAMPLE_SHIPPING => 'Sample Shipping',
            self::FREIGHT => 'Freight',
            self::CUSTOMS => 'Customs / Duties',
            self::INSURANCE => 'Insurance',
            self::PACKAGING => 'Packaging',
            self::CERTIFICATION => 'Certification',
            self::TRAVEL => 'Travel Expenses',
            self::COMMISSION => 'Commission',
            self::OTHER => 'Other',
        };
    }
}
