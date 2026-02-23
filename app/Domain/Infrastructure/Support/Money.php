<?php

namespace App\Domain\Infrastructure\Support;

class Money
{
    public const SCALE = 10000;

    public static function toMinor(float|int|string|null $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        return (int) round((float) $value * self::SCALE);
    }

    public static function toMajor(int|null $minorUnits): float
    {
        if ($minorUnits === null) {
            return 0;
        }

        return $minorUnits / self::SCALE;
    }

    public static function format(int|null $minorUnits, int $decimals = 2): string
    {
        return number_format(self::toMajor($minorUnits), $decimals, '.', ',');
    }

    public static function formatDisplay(int|null $minorUnits, int $decimals = 2): string
    {
        return number_format(self::toMajor($minorUnits), $decimals, '.', ',');
    }
}
