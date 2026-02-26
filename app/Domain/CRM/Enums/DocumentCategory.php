<?php

namespace App\Domain\CRM\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum DocumentCategory: string implements HasLabel, HasColor, HasIcon
{
    case CERTIFICATE = 'certificate';
    case PHOTO = 'photo';
    case CONTRACT = 'contract';
    case LICENSE = 'license';
    case REPORT = 'report';
    case PRICE_LIST = 'price_list';
    case CATALOG = 'catalog';
    case OTHER = 'other';

    public function getLabel(): ?string
    {
        return __('enums.document_category.' . $this->value);
    }


    public function getColor(): string
    {
        return match ($this) {
            self::CERTIFICATE => 'success',
            self::PHOTO => 'info',
            self::CONTRACT => 'warning',
            self::LICENSE => 'primary',
            self::REPORT => 'gray',
            self::PRICE_LIST => 'danger',
            self::CATALOG => 'info',
            self::OTHER => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::CERTIFICATE => 'heroicon-o-shield-check',
            self::PHOTO => 'heroicon-o-photo',
            self::CONTRACT => 'heroicon-o-document-text',
            self::LICENSE => 'heroicon-o-identification',
            self::REPORT => 'heroicon-o-chart-bar',
            self::PRICE_LIST => 'heroicon-o-currency-dollar',
            self::CATALOG => 'heroicon-o-book-open',
            self::OTHER => 'heroicon-o-paper-clip',
        };
    }

    public function getEnglishLabel(): string
    {
        return match ($this) {
            self::CERTIFICATE => 'Certificate',
            self::PHOTO => 'Photo',
            self::CONTRACT => 'Contract',
            self::LICENSE => 'License',
            self::REPORT => 'Report',
            self::PRICE_LIST => 'Price List',
            self::CATALOG => 'Catalog',
            self::OTHER => 'Other',
        };
    }
}
