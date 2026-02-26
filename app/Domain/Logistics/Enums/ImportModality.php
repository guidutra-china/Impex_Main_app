<?php

namespace App\Domain\Logistics\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum ImportModality: string implements HasLabel, HasColor, HasIcon
{
    case DIRECT = 'direct';
    case CONTA_E_ORDEM = 'conta_e_ordem';
    case ENCOMENDA = 'encomenda';

    public function getLabel(): ?string
    {
        return __('enums.import_modality.' . $this->value);
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::DIRECT => 'gray',
            self::CONTA_E_ORDEM => 'warning',
            self::ENCOMENDA => 'info',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::DIRECT => 'heroicon-o-arrow-right',
            self::CONTA_E_ORDEM => 'heroicon-o-arrows-right-left',
            self::ENCOMENDA => 'heroicon-o-clipboard-document-check',
        };
    }

    public function getEnglishLabel(): string
    {
        return match ($this) {
            self::DIRECT => 'Direct Import',
            self::CONTA_E_ORDEM => 'Import on Behalf (Conta e Ordem)',
            self::ENCOMENDA => 'Import by Order (Encomenda)',
        };
    }

    public function requiresNotifyParty(): bool
    {
        return match ($this) {
            self::DIRECT => false,
            self::CONTA_E_ORDEM => true,
            self::ENCOMENDA => true,
        };
    }
}
