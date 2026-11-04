<?php

declare(strict_types=1);

namespace Payroll\Tests\Unit\Domain;

use Payroll\Domain\Exception\InvalidMoneyAmount;
use Payroll\Domain\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    #[Test]
    #[DataProvider('validAmounts')]
    public function parsesDecimalStringsExactly(string $amount, int $expectedCents): void
    {
        self::assertSame($expectedCents, Money::fromString($amount)->cents);
    }

    /** @return array<string, array{string, int}> */
    public static function validAmounts(): array
    {
        $maximum = (string) PHP_INT_MAX;
        $minimum = (string) PHP_INT_MIN;

        return [
            'whole dollars' => ['1000', 100000],
            'system value' => ['1050.00', 105000],
            'negative adjustment' => ['-45.55', -4555],
            'explicit positive sign' => ['+100.10', 10010],
            'one decimal place' => ['0.2', 20],
            'two decimal places' => ['19.99', 1999],
            'precision regression' => ['0.29', 29],
            'one cent' => ['0.01', 1],
            'zero' => ['0', 0],
            'negative zero' => ['-0.00', 0],
            'positive zero' => ['+0.0', 0],
            'leading zeroes' => ['+00019.09', 1909],
            'largest amount' => [substr($maximum, 0, -2) . '.' . substr($maximum, -2), PHP_INT_MAX],
            'smallest amount' => [substr($minimum, 0, -2) . '.' . substr($minimum, -2), PHP_INT_MIN],
        ];
    }

    #[Test]
    #[DataProvider('invalidAmounts')]
    public function rejectsInvalidAmounts(string $amount): void
    {
        $this->expectException(InvalidMoneyAmount::class);

        Money::fromString($amount);
    }

    /** @return array<string, array{string}> */
    public static function invalidAmounts(): array
    {
        $aboveMaximum = intdiv(PHP_INT_MAX, 100) . '.'
            . str_pad((string) (PHP_INT_MAX % 100 + 1), 2, '0', STR_PAD_LEFT);
        $belowMinimum = intdiv(PHP_INT_MIN, 100) . '.'
            . str_pad((string) (abs(PHP_INT_MIN % 100) + 1), 2, '0', STR_PAD_LEFT);

        return [
            'empty' => [''],
            'non-numeric' => ['abc'],
            'excess precision' => ['1.234'],
            'scientific notation' => ['1e3'],
            'thousands separator' => ['1,000.00'],
            'repeated sign' => ['--1'],
            'mixed signs' => ['+-1'],
            'leading whitespace' => [' 5'],
            'trailing whitespace' => ['5 '],
            'trailing newline' => ["5\n"],
            'missing whole part' => ['.5'],
            'missing fractional part' => ['1.'],
            'one cent above maximum' => [$aboveMaximum],
            'one cent below minimum' => [$belowMinimum],
            'very large amount' => ['999999999999999999999999999999.99'],
        ];
    }

    #[Test]
    #[DataProvider('centAmounts')]
    public function preservesIntegerCents(int $cents): void
    {
        self::assertSame($cents, Money::fromCents($cents)->cents);
    }

    /** @return array<string, array{int}> */
    public static function centAmounts(): array
    {
        return [
            'zero' => [0],
            'positive' => [29],
            'negative' => [-4555],
            'maximum' => [PHP_INT_MAX],
            'minimum' => [PHP_INT_MIN],
        ];
    }

    #[Test]
    #[DataProvider('additionCases')]
    public function addsWithoutChangingOperands(int $leftCents, int $rightCents, int $expectedCents): void
    {
        $left = Money::fromCents($leftCents);
        $right = Money::fromCents($rightCents);

        $sum = $left->add($right);

        self::assertSame($expectedCents, $sum->cents);
        self::assertSame($leftCents, $left->cents);
        self::assertSame($rightCents, $right->cents);
    }

    /** @return array<string, array{int, int, int}> */
    public static function additionCases(): array
    {
        return [
            'negative adjustment' => [105000, -4555, 100445],
            'positive adjustment' => [100445, 10010, 110455],
            'small corrections' => [29, -20, 9],
            'compensating adjustment' => [-20, 20, 0],
            'zero' => [1999, 0, 1999],
            'negative result' => [-4555, -10, -4565],
            'reaches maximum' => [PHP_INT_MAX - 1, 1, PHP_INT_MAX],
            'reaches minimum' => [PHP_INT_MIN + 1, -1, PHP_INT_MIN],
            'opposite extremes' => [PHP_INT_MAX, PHP_INT_MIN, -1],
        ];
    }

    #[Test]
    #[DataProvider('overflowingAdditions')]
    public function rejectsAdditionOutsideTheIntegerRange(int $leftCents, int $rightCents): void
    {
        $this->expectException(InvalidMoneyAmount::class);

        Money::fromCents($leftCents)->add(Money::fromCents($rightCents));
    }

    /** @return array<string, array{int, int}> */
    public static function overflowingAdditions(): array
    {
        return [
            'above maximum' => [PHP_INT_MAX, 1],
            'below minimum' => [PHP_INT_MIN, -1],
            'two maximum amounts' => [PHP_INT_MAX, PHP_INT_MAX],
            'two minimum amounts' => [PHP_INT_MIN, PHP_INT_MIN],
        ];
    }

    #[Test]
    #[DataProvider('zeroCases')]
    public function identifiesZero(int $cents, bool $expected): void
    {
        self::assertSame($expected, Money::fromCents($cents)->isZero());
    }

    /** @return array<string, array{int, bool}> */
    public static function zeroCases(): array
    {
        return [
            'zero' => [0, true],
            'positive' => [1, false],
            'negative' => [-1, false],
        ];
    }

    #[Test]
    public function comparesAmountsByValue(): void
    {
        $amount = Money::fromString('0.29');

        self::assertTrue($amount->equals(Money::fromCents(29)));
        self::assertFalse($amount->equals(Money::fromCents(28)));
        self::assertFalse($amount->equals(Money::fromCents(-29)));
        self::assertTrue(Money::fromString('-0.00')->equals(Money::fromCents(0)));
    }

    #[Test]
    #[DataProvider('formattedAmounts')]
    public function formatsAmountsExactly(int $cents, string $formatted, string $signed): void
    {
        $amount = Money::fromCents($cents);

        self::assertSame($formatted, $amount->format());
        self::assertSame($signed, $amount->formatSigned());
    }

    /** @return array<string, array{int, string, string}> */
    public static function formattedAmounts(): array
    {
        $maximum = PHP_INT_SIZE === 8 ? '$92,233,720,368,547,758.07' : '$21,474,836.47';
        $minimum = PHP_INT_SIZE === 8 ? '-$92,233,720,368,547,758.08' : '-$21,474,836.48';

        return [
            'brief final value' => [110445, '$1,104.45', '+$1,104.45'],
            'negative adjustment' => [-4555, '-$45.55', '-$45.55'],
            'zero' => [0, '$0.00', '+$0.00'],
            'explicit positive adjustment' => [10010, '$100.10', '+$100.10'],
            'positive cent' => [1, '$0.01', '+$0.01'],
            'negative cent' => [-1, '-$0.01', '-$0.01'],
            'whole dollar' => [100, '$1.00', '+$1.00'],
            'multiple thousands groups' => [123456789, '$1,234,567.89', '+$1,234,567.89'],
            'maximum' => [PHP_INT_MAX, $maximum, '+' . $maximum],
            'minimum' => [PHP_INT_MIN, $minimum, $minimum],
        ];
    }
}
