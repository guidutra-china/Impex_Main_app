<?php

namespace App\Domain\Financial\Support;

/**
 * A taxa gravada numa alocação é sempre "moeda do documento por 1 da moeda
 * do pagamento" (doc = pmt × rate). No formulário o usuário pode preferir
 * ver a taxa invertida (ex.: USD → BRL em vez de BRL → USD); esta classe
 * converte entre o valor exibido e o valor gravado sem tocar na persistência.
 */
final class ExchangeRateDirection
{
    public const DECIMALS = 8;

    /** Valor gravado (doc por pmt) a partir do que está no campo. */
    public static function toStored(mixed $displayed, bool $inverted): ?string
    {
        $value = self::positive($displayed);
        if ($value === null) {
            return null;
        }

        return self::format($inverted ? 1 / $value : $value);
    }

    /** Valor a exibir no campo a partir do valor gravado (doc por pmt). */
    public static function toDisplayed(mixed $stored, bool $inverted): ?string
    {
        return self::toStored($stored, $inverted);
    }

    /** 1 / x, preservando vazio e zero. */
    public static function invert(mixed $value): ?string
    {
        $number = self::positive($value);

        return $number === null ? null : self::format(1 / $number);
    }

    public static function format(float $value): string
    {
        return number_format($value, self::DECIMALS, '.', '');
    }

    private static function positive(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return $number > 0 ? $number : null;
    }
}
