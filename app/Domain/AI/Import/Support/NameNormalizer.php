<?php

declare(strict_types=1);

namespace App\Domain\AI\Import\Support;

/**
 * Chave de comparação de nomes/descrições: só letras e números, maiúsculas.
 * Ignorar pontuação/espaços faz "Dumbbell — 5kg" casar com "Dumbbell 5kg" —
 * variações de formatação entre extrações não podem virar produto duplicado.
 */
class NameNormalizer
{
    public static function normalize(?string $name): string
    {
        return mb_strtoupper(preg_replace('/[^\p{L}\p{N}]+/u', '', (string) $name) ?? '');
    }
}
