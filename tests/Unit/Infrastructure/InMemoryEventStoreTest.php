<?php

declare(strict_types=1);

namespace Payroll\Tests\Unit\Infrastructure;

use DateTimeImmutable;
use Payroll\Application\ConcurrencyConflict;
use Payroll\Domain\AdjustmentComment;
use Payroll\Domain\EarningLineId;
use Payroll\Domain\Event\ManualAdjustmentAdded;
use Payroll\Domain\Event\SystemValueCalculated;
use Payroll\Domain\Money;
use Payroll\Infrastructure\InMemoryEventStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InMemoryEventStoreTest extends TestCase
{
    #[Test]
    public function returnsEmptyHistoryForAnUnknownStream(): void
    {
        self::assertSame([], new InMemoryEventStore()->load('unknown-line'));
    }

    #[Test]
    public function appendsBatchesWithoutReplacingOrReorderingEarlierEvents(): void
    {
        $store = new InMemoryEventStore();
        $initial = self::calculation('line-42', '1000.00');
        $recalculated = self::calculation('line-42', '1050.00');
        $adjustment = new ManualAdjustmentAdded(
            new EarningLineId('line-42'),
            1,
            Money::fromString('-45.55'),
            new AdjustmentComment('Benefit correction'),
            'specialist-7',
            new DateTimeImmutable('2026-01-01T11:00:00+00:00'),
        );

        $store->append('line-42', [$initial, $recalculated], 0);
        $store->append('line-42', [$adjustment], 2);

        self::assertEquals([$initial, $recalculated, $adjustment], $store->load('line-42'));
    }

    #[Test]
    public function keepsStreamsAndTheirVersionsIndependent(): void
    {
        $store = new InMemoryEventStore();
        $first = self::calculation('line-1', '1000.00');
        $second = self::calculation('line-2', '2000.00');
        $recalculated = self::calculation('line-1', '1050.00');

        $store->append('line-1', [$first], 0);
        $store->append('line-2', [$second], 0);
        $store->append('line-1', [$recalculated], 1);

        self::assertEquals([$first, $recalculated], $store->load('line-1'));
        self::assertEquals([$second], $store->load('line-2'));
    }

    #[Test]
    #[DataProvider('conflictingVersions')]
    public function rejectsTheEntireBatchWhenTheVersionDoesNotMatch(int $expectedVersion): void
    {
        $store = new InMemoryEventStore();
        $initial = self::calculation('line-42', '1000.00');
        $store->append('line-42', [$initial], 0);
        $this->expectException(ConcurrencyConflict::class);

        try {
            $store->append('line-42', [
                self::calculation('line-42', '1050.00'),
                self::calculation('line-42', '1100.00'),
            ], $expectedVersion);
        } finally {
            self::assertEquals([$initial], $store->load('line-42'));
        }
    }

    /** @return array<string, array{int}> */
    public static function conflictingVersions(): array
    {
        return [
            'stale writer' => [0],
            'future version' => [2],
            'negative version' => [-1],
        ];
    }

    #[Test]
    public function requiresVersionZeroWhenCreatingAStream(): void
    {
        $store = new InMemoryEventStore();
        $this->expectException(ConcurrencyConflict::class);

        try {
            $store->append('line-42', [self::calculation('line-42', '1000.00')], 1);
        } finally {
            self::assertSame([], $store->load('line-42'));
        }
    }

    #[Test]
    public function emptyAppendDoesNotChangeTheHistoryOrVersion(): void
    {
        $store = new InMemoryEventStore();
        $initial = self::calculation('line-42', '1000.00');
        $recalculated = self::calculation('line-42', '1050.00');
        $store->append('line-42', [$initial], 0);

        $store->append('line-42', [], 1);
        $store->append('line-42', [$recalculated], 1);

        self::assertEquals([$initial, $recalculated], $store->load('line-42'));
    }

    #[Test]
    public function checksTheExpectedVersionEvenForAnEmptyAppend(): void
    {
        $store = new InMemoryEventStore();
        $initial = self::calculation('line-42', '1000.00');
        $store->append('line-42', [$initial], 0);
        $this->expectException(ConcurrencyConflict::class);

        try {
            $store->append('line-42', [], 0);
        } finally {
            self::assertEquals([$initial], $store->load('line-42'));
        }
    }

    #[Test]
    public function changingLoadedHistoryDoesNotChangeStoredHistory(): void
    {
        $store = new InMemoryEventStore();
        $initial = self::calculation('line-42', '1000.00');
        $recalculated = self::calculation('line-42', '1050.00');
        $store->append('line-42', [$initial, $recalculated], 0);

        $loaded = $store->load('line-42');
        array_pop($loaded);
        $loaded[] = self::calculation('line-42', '2000.00');

        self::assertNotEquals($loaded, $store->load('line-42'));
        self::assertEquals([$initial, $recalculated], $store->load('line-42'));
    }

    #[Test]
    public function changingAnAppendedBatchDoesNotChangeStoredHistory(): void
    {
        $store = new InMemoryEventStore();
        $initial = self::calculation('line-42', '1000.00');
        $batch = [$initial];
        $store->append('line-42', $batch, 0);

        $batch[] = self::calculation('line-42', '1050.00');

        self::assertCount(2, $batch);
        self::assertEquals([$initial], $store->load('line-42'));
    }

    #[Test]
    public function separateStoreInstancesDoNotShareHistory(): void
    {
        $first = new InMemoryEventStore();
        $second = new InMemoryEventStore();
        $first->append('line-42', [self::calculation('line-42', '1000.00')], 0);

        self::assertSame([], $second->load('line-42'));
    }

    private static function calculation(string $lineId, string $amount): SystemValueCalculated
    {
        return new SystemValueCalculated(
            new EarningLineId($lineId),
            Money::fromString($amount),
            new DateTimeImmutable('2026-01-01T09:00:00+00:00'),
        );
    }
}
