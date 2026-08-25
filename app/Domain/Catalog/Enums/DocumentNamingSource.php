<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * De onde um documento tira código, nome e descrição do produto.
 *
 * COUNTERPARTY é o comportamento histórico: o pivot da contraparte vence.
 * SYSTEM ignora o pivot e usa o cadastro interno do produto.
 */
enum DocumentNamingSource: string implements HasLabel
{
    case COUNTERPARTY = 'counterparty';
    case SYSTEM = 'system';

    public function getLabel(): ?string
    {
        return __('enums.document_naming_source.'.$this->value);
    }

    public function isCounterparty(): bool
    {
        return $this === self::COUNTERPARTY;
    }
}
