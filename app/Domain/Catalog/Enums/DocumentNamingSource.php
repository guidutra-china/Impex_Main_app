<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/**
 * De onde um documento tira código, nome e descrição do produto.
 *
 * COUNTERPARTY é o comportamento histórico: o pivot da contraparte vence.
 * SYSTEM ignora o pivot e usa o cadastro interno do produto.
 */
enum DocumentNamingSource: string
{
    case Counterparty = 'counterparty';
    case System = 'system';

    public function isCounterparty(): bool
    {
        return $this === self::Counterparty;
    }
}
