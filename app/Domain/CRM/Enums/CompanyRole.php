<?php

namespace App\Domain\CRM\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CompanyRole: string implements HasLabel, HasColor
{
    case CLIENT = 'client';
    case SUPPLIER = 'supplier';
    case FORWARDER = 'forwarder';
    case AGENT = 'agent';
    case MANUFACTURER = 'manufacturer';

    public function getLabel(): ?string
    {
        return __('enums.company_role.' . $this->value);
    }


    public function getColor(): string|array|null
    {
        return match ($this) {
            self::CLIENT => 'success',
            self::SUPPLIER => 'info',
            self::FORWARDER => 'warning',
            self::AGENT => 'primary',
            self::MANUFACTURER => 'gray',
        };
    }

    public function getEnglishLabel(): string
    {
        return match ($this) {
            self::CLIENT => 'Client',
            self::SUPPLIER => 'Supplier',
            self::FORWARDER => 'Forwarder',
            self::AGENT => 'Agent',
            self::MANUFACTURER => 'Manufacturer',
        };
    }
}
