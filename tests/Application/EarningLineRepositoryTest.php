<?php

declare(strict_types=1);

namespace Payroll\Tests\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Payroll\Application\ConcurrencyConflict;
use Payroll\Application\CorruptedEventStream;
use Payroll\Application\EarningLineNotFound;
use Payroll\Application\EarningLineRepository;
use Payroll\Application\EventStore;
use Payroll\Domain\AdjustmentComment;
use Payroll\Domain\EarningLine;
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
use RuntimeException;

final class EarningLineRepositoryTest extends TestCase
{
    #[Test]
    public function findsNoAggregateForAnUnknownLine(): void
    {
        $repository = new EarningLineRepository(new InMemoryEventStore());

        self::assertNull($repository->find(new EarningLineId('unknown-line')));
    }

    #[Test]
    public function getRejectsAnUnknownLine(): void
    {
        $repository = new EarningLineRepository(new InMemoryEventStore());
        $this->expectException(EarningLineNotFound::class);

        $repository->get(new EarningLineId('unknown-line'));
    }

    #[Test]
    #[DataProvider('requiredReads')]
    public function rejectsHistoryStoredUnderAnotherLineId(bool $required): void
    {
        $store = new InMemoryEventStore();
        $repository = new EarningLineRepository($store);
        $foreignId = new EarningLineId('line-43');
        $events = [
            new SystemValueCalculated($foreignId, Money::fromString('1000.00'), self::at()),
            new ManualAdjustmentAdded($foreignId, 1, Money::fromString('1.00'), new AdjustmentComment('Correction'), 'user-1', self::at()),
            new SystemRecalculationIgnored($foreignId, Money::fromString('2000.00'), self::at()),
        ];
        $store->append('line-42', $events, 0);
        $requestedId = new EarningLineId('line-42');
        $this->expectException(CorruptedEventStream::class);
        $this->expectExceptionMessage('Event 1 in stream "line-42"');

        try {
            if ($required) {
                $repository->get($requestedId);
            } else {
                $repository->find($requestedId);
            }
        } finally {
            self::assertSame($events, $store->load('line-42'));
            self::assertSame([], $store->load('line-43'));
        }
    }

    /** @return array<string, array{bool}> */
    public static function requiredReads(): array
    {
        return [
            'find' => [false],
            'get' => [true],
        ];
    }

    /** @param list<DomainEvent> $events */
    #[Test]
    #[DataProvider('malformedHistories')]
    public function reportsCorruptedHistoryAndPreservesTheReplayFailure(array $events): void
    {
        $store = new InMemoryEventStore();
        $repository = new EarningLineRepository($store);
        $store->append('line-42', $events, 0);

        try {
            $repository->find(new EarningLineId('line-42'));
            self::fail('A malformed history must raise a corrupted stream exception.');
        } catch (CorruptedEventStream $exception) {
            self::assertStringContainsString('"line-42"', $exception->getMessage());
            self::assertInstanceOf(InvalidArgumentException::class, $exception->getPrevious());
            self::assertSame($events, $store->load('line-42'));
        }
    }

    /** @return array<string, array{list<DomainEvent>}> */
    public static function malformedHistories(): array
    {
        $id = new EarningLineId('line-42');
        $foreignId = new EarningLineId('line-43');
        $initial = new SystemValueCalculated($id, Money::fromString('1000.00'), self::at());

        return [
            'manual adjustment first' => [[
                new ManualAdjustmentAdded($id, 1, Money::fromString('1.00'), new AdjustmentComment('Correction'), 'user-1', self::at()),
            ]],
            'ignored recalculation first' => [[
                new SystemRecalculationIgnored($id, Money::fromString('1000.00'), self::at()),
            ]],
            'unknown event first' => [[self::unknownEvent($id)]],
            'unknown later event' => [[$initial, self::unknownEvent($id)]],
            'foreign system calculation' => [[
                $initial,
                new SystemValueCalculated($foreignId, Money::fromString('1050.00'), self::at()),
            ]],
            'foreign manual adjustment' => [[
                $initial,
                new ManualAdjustmentAdded($foreignId, 1, Money::fromString('1.00'), new AdjustmentComment('Correction'), 'user-1', self::at()),
            ]],
            'foreign ignored recalculation' => [[
                $initial,
                new SystemRecalculationIgnored($foreignId, Money::fromString('2000.00'), self::at()),
            ]],
        ];
    }

    #[Test]
    public function propagatesStoreLoadFailuresWithoutWrappingThemAsCorruptedHistory(): void
    {
        $failure = new InvalidArgumentException('Store load failed.');
        $store = $this->createStub(EventStore::class);
        $store->method('load')->willThrowException($failure);
        $repository = new EarningLineRepository($store);

        try {
            $repository->find(new EarningLineId('line-42'));
            self::fail('The store load failure must propagate.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame($failure, $exception);
        }
    }

    #[Test]
    public function findsAnAggregateUsingADistinctIdObjectWithTheSameValue(): void
    {
        $store = new InMemoryEventStore();
        $repository = new EarningLineRepository($store);
        $line = self::line();
        $repository->save($line);
        $requestedId = new EarningLineId('line-42');

        $restored = $repository->find($requestedId);

        self::assertNotNull($restored);
        self::assertNotSame($requestedId, $restored->id);
        self::assertTrue($restored->id->equals($requestedId));
        self::assertSame(100000, $restored->currentValue()->cents);
        self::assertSame([], $restored->releaseEvents());
    }

    #[Test]
    public function savesAllPendingEventsAndClearsThemOnlyAfterSuccess(): void
    {
        $store = new InMemoryEventStore();
        $repository = new EarningLineRepository($store);
        $line = self::line();
        $line->recalculate(Money::fromString('1050.00'), self::at());
        $pending = (clone $line)->releaseEvents();

        $repository->save($line);

        self::assertSame($pending, $store->load('line-42'));
        self::assertSame([], $line->releaseEvents());
        self::assertSame(2, $line->version);
        self::assertSame(105000, $line->currentValue()->cents);
    }

    #[Test]
    public function restoresTheAggregateWithoutRecordingHistoricalEvents(): void
    {
        $store = new InMemoryEventStore();
        $repository = new EarningLineRepository($store);
        $line = self::line();
        $line->addManualAdjustment(Money::fromString('-45.55'), new AdjustmentComment('Benefit'), 'user-1', self::at());
        $line->recalculate(Money::fromString('2000.00'), self::at());
        $repository->save($line);

        $restored = $repository->get(new EarningLineId('line-42'));

        self::assertNotSame($line, $restored);
        self::assertTrue($restored->id->equals($line->id));
        self::assertSame(95445, $restored->currentValue()->cents);
        self::assertSame(100000, $restored->systemValue->cents);
        self::assertSame(-4555, $restored->adjustmentsTotal->cents);
        self::assertSame(1, $restored->adjustmentCount);
        self::assertSame(3, $restored->version);
        self::assertTrue($restored->isLocked());
        self::assertSame([], $restored->releaseEvents());
    }

    #[Test]
    public function appendsOnlyNewEventsAfterLoadingAnAggregate(): void
    {
        $store = new InMemoryEventStore();
        $repository = new EarningLineRepository($store);
        $repository->save(self::line());
        $original = $store->load('line-42');
        $restored = $repository->get(new EarningLineId('line-42'));
        $restored->addManualAdjustment(Money::fromString('10.00'), new AdjustmentComment('Bonus'), 'user-1', self::at());
        $pending = (clone $restored)->releaseEvents();

        $repository->save($restored);

        self::assertSame([...$original, ...$pending], $store->load('line-42'));
        self::assertSame([], $restored->releaseEvents());
        self::assertSame(101000, $repository->get($restored->id)->currentValue()->cents);
    }

    #[Test]
    public function repeatedSaveDoesNotDuplicateEvents(): void
    {
        $store = new InMemoryEventStore();
        $repository = new EarningLineRepository($store);
        $line = self::line();
        $repository->save($line);
        $history = $store->load('line-42');

        $repository->save($line);
        $repository->save($repository->get($line->id));

        self::assertSame($history, $store->load('line-42'));
        self::assertSame(1, $repository->get($line->id)->version);
    }

    #[Test]
    public function concurrentSavePreservesBothStoredHistoryAndRejectedPendingEvents(): void
    {
        $store = new InMemoryEventStore();
        $repository = new EarningLineRepository($store);
        $repository->save(self::line());
        $first = $repository->get(new EarningLineId('line-42'));
        $second = $repository->get(new EarningLineId('line-42'));
        $first->addManualAdjustment(Money::fromString('10.00'), new AdjustmentComment('Bonus'), 'user-1', self::at());
        $second->addManualAdjustment(Money::fromString('-20.00'), new AdjustmentComment('Benefit'), 'user-2', self::at());
        $repository->save($first);
        $history = $store->load('line-42');
        $pending = (clone $second)->releaseEvents();

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $repository->save($second);
                self::fail('A stale aggregate must raise a concurrency conflict on every save attempt.');
            } catch (ConcurrencyConflict) {
                self::assertSame($history, $store->load('line-42'));
                self::assertSame($pending, (clone $second)->releaseEvents());
                self::assertSame(98000, $second->currentValue()->cents);
                self::assertSame(2, $second->version);
            }
        }
    }

    #[Test]
    public function pendingEventsCanBeRetriedAfterATemporaryStoreFailure(): void
    {
        $store = new class implements EventStore {
            private InMemoryEventStore $inner;

            private bool $failNextAppend = true;

            public function __construct()
            {
                $this->inner = new InMemoryEventStore();
            }

            /** @return list<DomainEvent> */
            public function load(string $streamId): array
            {
                return $this->inner->load($streamId);
            }

            /** @param list<DomainEvent> $events */
            public function append(string $streamId, array $events, int $expectedVersion): void
            {
                if ($this->failNextAppend) {
                    $this->failNextAppend = false;

                    throw new RuntimeException('Temporary store failure.');
                }

                $this->inner->append($streamId, $events, $expectedVersion);
            }
        };
        $repository = new EarningLineRepository($store);
        $line = self::line();
        $pending = (clone $line)->releaseEvents();

        try {
            $repository->save($line);
            self::fail('The first append should fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('Temporary store failure.', $exception->getMessage());
            self::assertSame([], $store->load('line-42'));
            self::assertSame($pending, (clone $line)->releaseEvents());
        }

        $repository->save($line);

        self::assertSame($pending, $store->load('line-42'));
        self::assertSame([], $line->releaseEvents());
    }

    private static function unknownEvent(EarningLineId $id): DomainEvent
    {
        return new readonly class ($id, self::at()) implements DomainEvent {
            public function __construct(public EarningLineId $lineId, public DateTimeImmutable $occurredAt)
            {
            }
        };
    }

    private static function line(): EarningLine
    {
        return EarningLine::calculate(new EarningLineId('line-42'), Money::fromString('1000.00'), self::at());
    }

    private static function at(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-01T09:00:00+00:00');
    }
}
