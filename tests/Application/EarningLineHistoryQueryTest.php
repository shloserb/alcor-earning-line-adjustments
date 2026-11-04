<?php

declare(strict_types=1);

namespace Payroll\Tests\Application;

use DateTimeImmutable;
use Payroll\Application\CorruptedEventStream;
use Payroll\Application\EarningLineNotFound;
use Payroll\Application\Query\AdjustmentEntry;
use Payroll\Application\Query\EarningLineHistoryQuery;
use Payroll\Application\Query\IgnoredRecalculation;
use Payroll\Domain\AdjustmentComment;
use Payroll\Domain\EarningLineId;
use Payroll\Domain\Event\DomainEvent;
use Payroll\Domain\Event\ManualAdjustmentAdded;
use Payroll\Domain\Event\SystemRecalculationIgnored;
use Payroll\Domain\Event\SystemValueCalculated;
use Payroll\Domain\Money;
use Payroll\Infrastructure\InMemoryEventStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EarningLineHistoryQueryTest extends TestCase
{
    private InMemoryEventStore $store;

    private EarningLineHistoryQuery $query;

    protected function setUp(): void
    {
        $this->store = new InMemoryEventStore();
        $this->query = new EarningLineHistoryQuery($this->store);
    }

    #[Test]
    #[DataProvider('unknownLineIds')]
    public function rejectsAnUnknownLine(string $lineId): void
    {
        $this->expectException(EarningLineNotFound::class);

        $this->query->forLine($lineId);
    }

    /** @return array<string, array{string}> */
    public static function unknownLineIds(): array
    {
        return [
            'unknown ID' => ['unknown-line'],
            'empty ID' => [''],
        ];
    }

    /** @param list<DomainEvent> $events */
    #[Test]
    #[DataProvider('historiesWithoutInitialCalculation')]
    public function rejectsHistoryWithoutInitialSystemCalculation(array $events): void
    {
        $this->assertHistoryIsRejectedWithoutChangingTheStore($events);
    }

    /** @return array<string, array{list<DomainEvent>}> */
    public static function historiesWithoutInitialCalculation(): array
    {
        $id = new EarningLineId('line-42');

        return [
            'manual adjustment first' => [[
                new ManualAdjustmentAdded($id, 1, Money::fromString('1.00'), new AdjustmentComment('Correction'), 'user-1', self::at()),
            ]],
            'ignored recalculation first' => [[
                new SystemRecalculationIgnored($id, Money::fromString('1000.00'), self::at()),
            ]],
            'unknown event first' => [[self::unknownEvent($id)]],
        ];
    }

    #[Test]
    public function rejectsHistoryContainingAnUnsupportedEvent(): void
    {
        $id = new EarningLineId('line-42');

        $this->assertHistoryIsRejectedWithoutChangingTheStore([
            new SystemValueCalculated($id, Money::fromString('1000.00'), self::at()),
            self::unknownEvent($id),
        ], 2);
    }

    #[Test]
    #[DataProvider('eventsFromAnotherLine')]
    public function rejectsHistoryContainingAnEventFromAnotherLine(DomainEvent $foreignEvent): void
    {
        $this->assertHistoryIsRejectedWithoutChangingTheStore([
            new SystemValueCalculated(new EarningLineId('line-42'), Money::fromString('1000.00'), self::at()),
            $foreignEvent,
        ], 2);
    }

    /** @return array<string, array{DomainEvent}> */
    public static function eventsFromAnotherLine(): array
    {
        $id = new EarningLineId('line-43');

        return [
            'system calculation' => [new SystemValueCalculated($id, Money::fromString('1050.00'), self::at())],
            'manual adjustment' => [
                new ManualAdjustmentAdded($id, 1, Money::fromString('1.00'), new AdjustmentComment('Correction'), 'user-1', self::at()),
            ],
            'ignored recalculation' => [new SystemRecalculationIgnored($id, Money::fromString('2000.00'), self::at())],
        ];
    }

    #[Test]
    public function rejectsHistoryStoredUnderAnotherLineId(): void
    {
        $id = new EarningLineId('line-43');

        $this->assertHistoryIsRejectedWithoutChangingTheStore([
            new SystemValueCalculated($id, Money::fromString('1000.00'), self::at()),
            new ManualAdjustmentAdded($id, 1, Money::fromString('1.00'), new AdjustmentComment('Correction'), 'user-1', self::at()),
            new SystemRecalculationIgnored($id, Money::fromString('2000.00'), self::at()),
        ]);
    }

    #[Test]
    public function acceptsDistinctIdObjectsWithTheSameValue(): void
    {
        $events = [
            new SystemValueCalculated(new EarningLineId('line-42'), Money::fromString('1000.00'), self::at()),
            new ManualAdjustmentAdded(
                new EarningLineId('line-42'),
                1,
                Money::fromString('1.00'),
                new AdjustmentComment('Correction'),
                'user-1',
                self::at(),
            ),
            new SystemRecalculationIgnored(new EarningLineId('line-42'), Money::fromString('2000.00'), self::at()),
        ];
        $this->store->append('line-42', $events, 0);

        $history = $this->query->forLine('line-42');

        self::assertSame('line-42', $history->lineId->value);
        self::assertSame(100100, $history->currentValue->cents);
        self::assertCount(1, $history->adjustments);
        self::assertCount(1, $history->ignoredRecalculations);
        self::assertSame($events, $this->store->load('line-42'));
    }

    #[Test]
    public function projectsAnInitialUnlockedCalculation(): void
    {
        $id = new EarningLineId('line-42');
        $this->store->append('line-42', [new SystemValueCalculated($id, Money::fromString('1000.00'), self::at())], 0);

        $history = $this->query->forLine('line-42');

        self::assertTrue($history->lineId->equals($id));
        self::assertSame(100000, $history->systemValue->cents);
        self::assertSame(100000, $history->currentValue->cents);
        self::assertNull($history->lockedAt);
        self::assertSame([], $history->adjustments);
        self::assertSame([], $history->ignoredRecalculations);
    }

    #[Test]
    public function usesTheLatestCalculationBeforeAnyManualAdjustment(): void
    {
        $id = new EarningLineId('line-42');
        $this->store->append('line-42', [
            new SystemValueCalculated($id, Money::fromString('1000.00'), self::at()),
            new SystemValueCalculated($id, Money::fromString('1050.00'), self::at('10:00:00')),
        ], 0);

        $history = $this->query->forLine('line-42');

        self::assertSame(105000, $history->systemValue->cents);
        self::assertSame(105000, $history->currentValue->cents);
        self::assertNull($history->lockedAt);
        self::assertSame([], $history->adjustments);
    }

    #[Test]
    public function projectsHistoricalCalculationsWithoutReapplyingTheCurrentLockPolicy(): void
    {
        $id = new EarningLineId('line-42');
        $adjustedAt = self::at('10:00:00');
        $comment = new AdjustmentComment('Benefit correction');
        $events = [
            new SystemValueCalculated($id, Money::fromString('1000.00'), self::at()),
            new ManualAdjustmentAdded($id, 1, Money::fromString('-45.55'), $comment, 'user-1', $adjustedAt),
            new SystemValueCalculated($id, Money::fromString('1075.00'), self::at('11:00:00')),
        ];
        $this->store->append('line-42', $events, 0);

        $history = $this->query->forLine('line-42');

        self::assertSame(107500, $history->systemValue->cents);
        self::assertSame(102945, $history->currentValue->cents);
        self::assertEquals($adjustedAt, $history->lockedAt);
        self::assertEquals([
            new AdjustmentEntry(1, Money::fromString('-45.55'), $comment, 'user-1', $adjustedAt),
        ], $history->adjustments);
        self::assertSame([], $history->ignoredRecalculations);
        self::assertSame($events, $this->store->load('line-42'));
    }

    #[Test]
    public function preservesAuditOrderAndTheFirstLockEvenAfterCompensation(): void
    {
        $id = new EarningLineId('line-42');
        $firstAt = self::at('11:00:00');
        $secondAt = self::at('10:00:00');
        $firstComment = new AdjustmentComment('Mistaken correction');
        $secondComment = new AdjustmentComment('Correcting adjustment #1');
        $this->store->append('line-42', [
            new SystemValueCalculated($id, Money::fromString('1000.00'), self::at()),
            new ManualAdjustmentAdded($id, 1, Money::fromString('-0.20'), $firstComment, 'user-1', $firstAt),
            new SystemRecalculationIgnored($id, Money::fromString('1075.00'), self::at('12:00:00')),
            new ManualAdjustmentAdded($id, 2, Money::fromString('+0.20'), $secondComment, 'user-2', $secondAt),
            new SystemRecalculationIgnored($id, Money::fromString('1200.00'), self::at('13:00:00')),
        ], 0);

        $history = $this->query->forLine('line-42');

        self::assertSame(100000, $history->systemValue->cents);
        self::assertSame(100000, $history->currentValue->cents);
        self::assertEquals($firstAt, $history->lockedAt);
        self::assertEquals([
            new AdjustmentEntry(1, Money::fromString('-0.20'), $firstComment, 'user-1', $firstAt),
            new AdjustmentEntry(2, Money::fromString('+0.20'), $secondComment, 'user-2', $secondAt),
        ], $history->adjustments);
        self::assertEquals([
            new IgnoredRecalculation(Money::fromString('1075.00'), self::at('12:00:00')),
            new IgnoredRecalculation(Money::fromString('1200.00'), self::at('13:00:00')),
        ], $history->ignoredRecalculations);
    }

    #[Test]
    public function laterEventsDoNotChangePreviouslyReturnedHistory(): void
    {
        $id = new EarningLineId('line-42');
        $this->store->append('line-42', [new SystemValueCalculated($id, Money::fromString('1000.00'), self::at())], 0);
        $before = $this->query->forLine('line-42');
        $at = self::at('10:00:00');
        $this->store->append('line-42', [
            new ManualAdjustmentAdded($id, 1, Money::fromString('10.00'), new AdjustmentComment('Bonus'), 'user-1', $at),
        ], 1);
        $events = $this->store->load('line-42');

        $after = $this->query->forLine('line-42');
        $again = $this->query->forLine('line-42');

        self::assertSame(100000, $before->currentValue->cents);
        self::assertNull($before->lockedAt);
        self::assertSame([], $before->adjustments);
        self::assertSame(101000, $after->currentValue->cents);
        self::assertEquals($at, $after->lockedAt);
        self::assertCount(1, $after->adjustments);
        self::assertEquals($after, $again);
        self::assertSame($events, $this->store->load('line-42'));
    }

    #[Test]
    public function keepsHistoriesOfDifferentLinesSeparate(): void
    {
        $firstId = new EarningLineId('line-1');
        $secondId = new EarningLineId('line-2');
        $this->store->append('line-1', [
            new SystemValueCalculated($firstId, Money::fromString('1000.00'), self::at()),
            new ManualAdjustmentAdded($firstId, 1, Money::fromString('10.00'), new AdjustmentComment('Bonus'), 'user-1', self::at()),
        ], 0);
        $this->store->append('line-2', [new SystemValueCalculated($secondId, Money::fromString('2000.00'), self::at())], 0);

        $first = $this->query->forLine('line-1');
        $second = $this->query->forLine('line-2');

        self::assertSame('line-1', $first->lineId->value);
        self::assertSame(101000, $first->currentValue->cents);
        self::assertCount(1, $first->adjustments);
        self::assertSame('line-2', $second->lineId->value);
        self::assertSame(200000, $second->currentValue->cents);
        self::assertSame([], $second->adjustments);
        self::assertSame([], $second->ignoredRecalculations);
        self::assertNull($second->lockedAt);
    }

    #[Test]
    public function projectsNegativeCurrentValuesWithoutLosingPrecision(): void
    {
        $id = new EarningLineId('line-42');
        $this->store->append('line-42', [
            new SystemValueCalculated($id, Money::fromString('0.29'), self::at()),
            new ManualAdjustmentAdded($id, 1, Money::fromString('-0.30'), new AdjustmentComment('Correction'), 'user-1', self::at()),
        ], 0);

        $history = $this->query->forLine('line-42');

        self::assertSame(29, $history->systemValue->cents);
        self::assertSame(-1, $history->currentValue->cents);
        self::assertSame('-$0.01', $history->currentValue->format());
    }

    /** @param list<DomainEvent> $events */
    private function assertHistoryIsRejectedWithoutChangingTheStore(array $events, int $eventPosition = 1): void
    {
        $this->store->append('line-42', $events, 0);
        $this->expectException(CorruptedEventStream::class);
        $this->expectExceptionMessage(sprintf('Event %d in stream "line-42"', $eventPosition));

        try {
            $this->query->forLine('line-42');
        } finally {
            self::assertSame($events, $this->store->load('line-42'));
        }
    }

    private static function unknownEvent(EarningLineId $id): DomainEvent
    {
        return new readonly class ($id, self::at()) implements DomainEvent {
            public function __construct(public EarningLineId $lineId, public DateTimeImmutable $occurredAt)
            {
            }
        };
    }

    private static function at(string $time = '09:00:00'): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-01T' . $time . '+00:00');
    }
}
