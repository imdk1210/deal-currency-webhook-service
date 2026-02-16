<?php

namespace App\Support;

use InvalidArgumentException;

class Money
{
    public static function decimalToMinorUnits(string|int|float $value, int $scale = 2): int
    {
        $decimal = self::toDecimalString($value, $scale);
        $multiplier = self::multiplier($scale);
        $minor = bcmul($decimal, $multiplier, 0);

        return (int) $minor;
    }

    public static function minorUnitsToDecimalString(int $minorUnits, int $scale = 2): string
    {
        $negative = $minorUnits < 0;
        $absolute = (string) abs($minorUnits);

        if ($scale === 0) {
            return $negative ? '-' . $absolute : $absolute;
        }

        if (strlen($absolute) <= $scale) {
            $absolute = str_pad($absolute, $scale + 1, '0', STR_PAD_LEFT);
        }

        $intPart = substr($absolute, 0, -$scale);
        $fractionalPart = substr($absolute, -$scale);
        $decimal = $intPart . '.' . $fractionalPart;

        return $negative ? '-' . $decimal : $decimal;
    }

    public static function multiplyMinorByRate(int $minorUnits, string $rate, int $scale = 2): int
    {
        $normalizedRate = self::toDecimalString($rate, 8);
        $amount = self::minorUnitsToDecimalString($minorUnits, $scale);
        $product = bcmul($amount, $normalizedRate, 12);
        $rounded = self::roundHalfUp($product, $scale);
        $multiplier = self::multiplier($scale);

        return (int) bcmul($rounded, $multiplier, 0);
    }

    public static function toDecimalString(string|int|float $value, int $scale = 2): string
    {
        if (is_int($value)) {
            return number_format($value, $scale, '.', '');
        }

        if (is_float($value)) {
            $value = rtrim(rtrim(sprintf('%.12F', $value), '0'), '.');
        }

        $stringValue = trim((string) $value);

        if (!preg_match('/^-?\d+(?:\.\d+)?$/', $stringValue)) {
            throw new InvalidArgumentException('Invalid decimal value: ' . $stringValue);
        }

        return self::roundHalfUp($stringValue, $scale);
    }

    public static function roundHalfUp(string $decimal, int $scale): string
    {
        if ($scale < 0) {
            throw new InvalidArgumentException('Scale must be >= 0');
        }

        $precision = $scale + 1;
        $halfUnit = '0.' . str_repeat('0', $scale) . '5';
        $isNegative = bccomp($decimal, '0', $precision + 2) < 0;

        $adjusted = $isNegative
            ? bcsub($decimal, $halfUnit, $precision)
            : bcadd($decimal, $halfUnit, $precision);

        return bcadd($adjusted, '0', $scale);
    }

    private static function multiplier(int $scale): string
    {
        return '1' . str_repeat('0', $scale);
    }
}
