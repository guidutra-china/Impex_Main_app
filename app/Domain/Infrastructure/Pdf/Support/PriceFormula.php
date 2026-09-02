<?php

namespace App\Domain\Infrastructure\Pdf\Support;

use App\Domain\Infrastructure\Support\Money;

/**
 * Fórmula simples aplicada a um preço unitário na geração de documentos:
 * "*0.70", "/1.2", "+10", "-5".
 *
 * Multiplicação e divisão operam sobre o valor em minor units direto;
 * soma e subtração recebem o operando em unidades maiores (o usuário digita
 * "+10" pensando em 10 dólares, não em 10 centésimos de milésimo).
 *
 * Fórmula inválida devolve o valor original — a validação do formato é
 * responsabilidade do formulário, e um documento nunca deve sair com preço
 * zerado por causa de um texto malformado.
 */
class PriceFormula
{
    public static function apply(int $value, ?string $formula): int
    {
        if (blank($formula)) {
            return $value;
        }

        $formula = trim($formula);

        if (! preg_match('/^([*\/+\-])\s*([0-9]*\.?[0-9]+)$/', $formula, $matches)) {
            return $value;
        }

        $operator = $matches[1];
        $operand = (float) $matches[2];

        return match ($operator) {
            '*' => (int) round($value * $operand),
            '/' => $operand != 0 ? (int) round($value / $operand) : $value,
            '+' => (int) round($value + ($operand * Money::SCALE)),
            '-' => (int) round($value - ($operand * Money::SCALE)),
            default => $value,
        };
    }
}
