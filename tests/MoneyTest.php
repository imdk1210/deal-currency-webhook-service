<?php

declare(strict_types=1);

namespace Tests;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testDecimalToMinorUnitsConvertsExactly(): void
    {
        self::assertSame(12345, Money::decimalToMinorUnits('123.45'));
    }

    public function testRoundHalfUpForHalfCent(): void
    {
        self::assertSame('1.01', Money::roundHalfUp('1.005', 2));
        self::assertSame('1.00', Money::roundHalfUp('1.004', 2));
    }

    public function testMultiplyMinorByRateWithoutFloatErrors(): void
    {
        $rubMinorUnits = 150000; // 1500.00 RUB
        $usdMinorUnits = Money::multiplyMinorByRate($rubMinorUnits, '0.01234567');

        self::assertSame(1852, $usdMinorUnits);
        self::assertSame('18.52', Money::minorUnitsToDecimalString($usdMinorUnits));
    }
}
