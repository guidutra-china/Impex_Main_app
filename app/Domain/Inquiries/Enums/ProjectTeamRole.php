<?php

namespace App\Domain\Inquiries\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum ProjectTeamRole: string implements HasLabel, HasColor, HasIcon
{
    case PROJECT_LEAD = 'project_lead';
    case SALES = 'sales';
    case SOURCING = 'sourcing';
    case LOGISTICS = 'logistics';
    case FINANCIAL = 'financial';
    case QUALITY = 'quality';

    public function getLabel(): ?string
    {
        return __('enums.project_team_role.' . $this->value);
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::PROJECT_LEAD => 'danger',
            self::SALES => 'success',
            self::SOURCING => 'warning',
            self::LOGISTICS => 'info',
            self::FINANCIAL => 'primary',
            self::QUALITY => 'gray',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::PROJECT_LEAD => 'heroicon-o-star',
            self::SALES => 'heroicon-o-currency-dollar',
            self::SOURCING => 'heroicon-o-magnifying-glass',
            self::LOGISTICS => 'heroicon-o-truck',
            self::FINANCIAL => 'heroicon-o-banknotes',
            self::QUALITY => 'heroicon-o-shield-check',
        };
    }

    public function getModuleMapping(): ?string
    {
        return match ($this) {
            self::PROJECT_LEAD => null,
            self::SALES => 'quotation',
            self::SOURCING => 'supplier_quotation',
            self::LOGISTICS => 'shipment',
            self::FINANCIAL => 'proforma_invoice',
            self::QUALITY => null,
        };
    }
}
