<?php

declare(strict_types=1);

namespace Payroll\Tests\Unit\Domain;

use InvalidArgumentException;
use Payroll\Domain\EarningLineId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EarningLineIdTest extends TestCase
{
    #[Test]
    public function preservesTheProvidedIdentifier(): void
    {
        self::assertSame('payroll-line-42', new EarningLineId('payroll-line-42')->value);
        self::assertSame('0', new EarningLineId('0')->value);
        self::assertSame(' line-42 ', new EarningLineId(' line-42 ')->value);
    }

    #[Test]
    #[DataProvider('emptyIdentifiers')]
    public function rejectsEmptyIdentifiers(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EarningLineId($value);
    }

    /** @return array<string, array{string}> */
    public static function emptyIdentifiers(): array
    {
        return [
            'empty' => [''],
            'spaces' => ['   '],
            'other whitespace' => ["\t\r\n"],
        ];
    }

    #[Test]
    public function comparesIdentifiersByValue(): void
    {
        $identifier = new EarningLineId('line-42');

        self::assertTrue($identifier->equals(new EarningLineId('line-42')));
        self::assertFalse($identifier->equals(new EarningLineId('line-43')));
        self::assertFalse($identifier->equals(new EarningLineId('LINE-42')));
    }
}
