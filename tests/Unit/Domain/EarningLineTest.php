<?php

declare(strict_types=1);

namespace Payroll\Tests\Unit\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Payroll\Domain\AdjustmentComment;
use Payroll\Domain\EarningLine;
use Payroll\Domain\EarningLineId;
use Payroll\Domain\Event\DomainEvent;
use Payroll\Domain\Event\ManualAdjustmentAdded;
use Payroll\Domain\Event\SystemRecalculationIgnored;
use Payroll\Domain\Event\SystemValueCalculated;
use Payroll\Domain\Exception\InvalidAdjustment;
use Payroll\Domain\Exception\InvalidMoneyAmount;
use Payroll\Domain\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EarningLineTest extends TestCase
{
    #[Test]
    public function calculatesInitialSystemValue(): void
    {
        $id = new EarningLineId('line-42');
        $amount = Money::fromString('1000.00');
        $at = self::at();

        $line = EarningLine::calculate($id, $amount, $at);

        self::assertTrue($line->id->equals($id));
        self::assertSame(100000, $line->systemValue->cents);
        self::assertSame(100000, $line->currentValue()->cents);
        self::assertSame(0, $line->adjustmentsTotal->cents);
        self::assertSame(0, $line->adjustmentCount);
        self::assertSame(1, $line->version);
        self::assertFalse($line->isLocked());
        self::assertEquals([new SystemValueCalculated($id, $amount, $at)], $line->releaseEvents());
    }

    #[Test]
    public function recalculatesSystemValueBeforeAnyManualAdjustment(): void
    {
        $line = self::calculatedLine();
        $line->releaseEvents();
        $amount = Money::fromString('1050.00');
        $at = self::at('10:00:00');

        $line->recalculate($amount, $at);

        self::assertSame(105000, $line->systemValue->cents);
        self::assertSame(105000, $line->currentValue()->cents);
        self::assertSame(0, $line->adjustmentCount);
        self::assertSame(2, $line->version);
        self::assertFalse($line->isLocked());
        self::assertEquals([new SystemValueCalculated($line->id, $amount, $at)], $line->releaseEvents());
    }

    #[Test]
    public function recordsManualAdjustmentWithItsAuditMetadata(): void
    {
        $line = self::calculatedLine();
        $line->releaseEvents();
        $amount = Money::fromString('-45.55');
        $comment = new AdjustmentComment('Employee declined dental benefit; reversing deduction');
        $at = self::at('11:00:00');

        $line->addManualAdjustment($amount, $comment, ' specialist-7 ', $at);

        self::assertSame(100000, $line->systemValue->cents);
        self::assertSame(-4555, $line->adjustmentsTotal->cents);
        self::assertSame(95445, $line->currentValue()->cents);
        self::assertSame(1, $line->adjustmentCount);
        self::assertSame(2, $line->version);
        self::assertTrue($line->isLocked());
        self::assertEquals(
            [new ManualAdjustmentAdded($line->id, 1, $amount, $comment, ' specialist-7 ', $at)],
            $line->releaseEvents(),
        );
    }

    #[Test]
    public function ignoresRecalculationAfterFirstAdjustment(): void
    {
        $line = self::calculatedLine('1050.00');
        $line->addManualAdjustment(
            Money::fromString('-45.55'),
            new AdjustmentComment('Benefit correction'),
            'specialist-7',
            self::at('10:00:00'),
        );
        $line->releaseEvents();
        $attemptedAmount = Money::fromString('1075.00');
        $at = self::at('11:00:00');

        $line->recalculate($attemptedAmount, $at);

        self::assertSame(105000, $line->systemValue->cents);
        self::assertSame(-4555, $line->adjustmentsTotal->cents);
        self::assertSame(100445, $line->currentValue()->cents);
        self::assertSame(1, $line->adjustmentCount);
        self::assertSame(3, $line->version);
        self::assertTrue($line->isLocked());
        self::assertEquals(
            [new SystemRecalculationIgnored($line->id, $attemptedAmount, $at)],
            $line->releaseEvents(),
        );
    }

    #[Test]
    public function lockIsPermanentEvenWhenAdjustmentsNetToZero(): void
    {
        $line = self::calculatedLine();
        $line->addManualAdjustment(Money::fromString('-0.20'), new AdjustmentComment('Correction'), 'user-1', self::at());
        $line->addManualAdjustment(Money::fromString('+0.20'), new AdjustmentComment('Compensation'), 'user-1', self::at());
        $line->releaseEvents();

        $line->recalculate(Money::fromString('2000.00'), self::at('12:00:00'));

        self::assertSame(0, $line->adjustmentsTotal->cents);
        self::assertSame(100000, $line->systemValue->cents);
        self::assertSame(100000, $line->currentValue()->cents);
        self::assertSame(2, $line->adjustmentCount);
        self::assertTrue($line->isLocked());
        $events = $line->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(SystemRecalculationIgnored::class, $events[0]);
    }

    #[Test]
    public function rejectsZeroAmount(): void
    {
        $line = self::calculatedLine();
        $line->releaseEvents();
        $before = self::state($line);
        $this->expectException(InvalidAdjustment::class);

        try {
            $line->addManualAdjustment(Money::fromCents(0), new AdjustmentComment('Correction'), 'user-1', self::at());
        } finally {
            self::assertSame($before, self::state($line));
            self::assertSame([], $line->releaseEvents());
        }
    }

    #[Test]
    #[DataProvider('emptyActors')]
    public function rejectsEmptyAdjustedBy(string $adjustedBy): void
    {
        $line = self::calculatedLine();
        $line->releaseEvents();
        $before = self::state($line);
        $this->expectException(InvalidAdjustment::class);

        try {
            $line->addManualAdjustment(Money::fromString('1.00'), new AdjustmentComment('Correction'), $adjustedBy, self::at());
        } finally {
            self::assertSame($before, self::state($line));
            self::assertSame([], $line->releaseEvents());
        }
    }

    /** @return array<string, array{string}> */
    public static function emptyActors(): array
    {
        return [
            'empty' => [''],
            'spaces' => ['   '],
            'other whitespace' => ["\t\r\n"],
        ];
    }

    #[Test]
    public function rejectedAdjustmentPreservesPreviouslyRecordedEvents(): void
    {
        $line = self::calculatedLine();
        $amount = Money::fromString('10.00');
        $comment = new AdjustmentComment('Approved overtime');
        $at = self::at('10:00:00');
        $line->addManualAdjustment($amount, $comment, 'user-1', $at);
        $before = self::state($line);
        $this->expectException(InvalidAdjustment::class);

        try {
            $line->addManualAdjustment(Money::fromCents(0), new AdjustmentComment('Invalid correction'), 'user-1', $at);
        } finally {
            self::assertSame($before, self::state($line));
            self::assertEquals([
                new SystemValueCalculated($line->id, Money::fromString('1000.00'), self::at()),
                new ManualAdjustmentAdded($line->id, 1, $amount, $comment, 'user-1', $at),
            ], $line->releaseEvents());
        }
    }

    #[Test]
    #[DataProvider('overflowingAdjustments')]
    public function rejectsOverflowWithoutChangingStateOrRecordingEvents(int $base, ?int $previous, int $attempted): void
    {
        $line = EarningLine::calculate(new EarningLineId('line-42'), Money::fromCents($base), self::at());
        $comment = new AdjustmentComment('Correction');

        if ($previous !== null) {
            $line->addManualAdjustment(Money::fromCents($previous), $comment, 'user-1', self::at());
        }

        $line->releaseEvents();
        $before = self::state($line);
        $this->expectException(InvalidMoneyAmount::class);

        try {
            $line->addManualAdjustment(Money::fromCents($attempted), $comment, 'user-1', self::at());
        } finally {
            self::assertSame($before, self::state($line));
            self::assertSame([], $line->releaseEvents());
        }
    }

    /** @return array<string, array{int, int|null, int}> */
    public static function overflowingAdjustments(): array
    {
        return [
            'current value above maximum' => [PHP_INT_MAX, null, 1],
            'current value below minimum' => [PHP_INT_MIN, null, -1],
            'adjustments total above maximum' => [-100, PHP_INT_MAX, 1],
            'adjustments total below minimum' => [100, PHP_INT_MIN, -1],
        ];
    }

    #[Test]
    public function incrementsAdjustmentSequenceAcrossReleasesAndIgnoredRecalculations(): void
    {
        $line = self::calculatedLine();
        $line->releaseEvents();

        foreach (['10.00', '-0.20', '+0.20'] as $index => $amount) {
            $line->addManualAdjustment(Money::fromString($amount), new AdjustmentComment('Correction'), 'user-1', self::at());
            $events = $line->releaseEvents();
            self::assertCount(1, $events);
            self::assertInstanceOf(ManualAdjustmentAdded::class, $events[0]);
            self::assertSame($index + 1, $events[0]->sequence);

            $line->recalculate(Money::fromString('2000.00'), self::at());
            $line->releaseEvents();
        }

        self::assertSame(3, $line->adjustmentCount);
        self::assertSame(7, $line->version);
    }

    #[Test]
    public function releaseEventsClearsOnlyThePendingEvents(): void
    {
        $line = self::calculatedLine();
        $line->addManualAdjustment(Money::fromString('10.00'), new AdjustmentComment('Bonus'), 'user-1', self::at());
        $before = self::state($line);

        $released = $line->releaseEvents();

        self::assertCount(2, $released);
        self::assertSame($before, self::state($line));
        self::assertSame([], $line->releaseEvents());

        $line->recalculate(Money::fromString('2000.00'), self::at());
        $newEvents = $line->releaseEvents();
        self::assertCount(1, $newEvents);
        self::assertInstanceOf(SystemRecalculationIgnored::class, $newEvents[0]);
        self::assertCount(2, $released);
    }

    #[Test]
    public function mistakeIsFixedByCompensatingAdjustment(): void
    {
        $line = self::calculatedLine();
        $line->releaseEvents();
        $line->addManualAdjustment(Money::fromString('-0.20'), new AdjustmentComment('Mistaken correction'), 'user-1', self::at());
        self::assertSame(99980, $line->currentValue()->cents);
        $line->addManualAdjustment(Money::fromString('+0.20'), new AdjustmentComment('Correcting adjustment #1'), 'user-2', self::at());

        self::assertSame(100000, $line->currentValue()->cents);
        self::assertSame(2, $line->adjustmentCount);
        $events = $line->releaseEvents();
        self::assertCount(2, $events);
        self::assertInstanceOf(ManualAdjustmentAdded::class, $events[0]);
        self::assertInstanceOf(ManualAdjustmentAdded::class, $events[1]);
        self::assertSame(-20, $events[0]->amount->cents);
        self::assertSame('Mistaken correction', $events[0]->comment->value);
        self::assertSame(1, $events[0]->sequence);
        self::assertSame(20, $events[1]->amount->cents);
        self::assertSame('Correcting adjustment #1', $events[1]->comment->value);
        self::assertSame(2, $events[1]->sequence);
    }

    #[Test]
    public function reconstitutesStateWithoutRecordingPastEventsAndContinuesSequence(): void
    {
        $line = self::calculatedLine();
        $line->recalculate(Money::fromString('1050.00'), self::at());
        $line->addManualAdjustment(Money::fromString('-0.20'), new AdjustmentComment('Correction'), 'user-1', self::at());
        $line->addManualAdjustment(Money::fromString('+0.20'), new AdjustmentComment('Compensation'), 'user-1', self::at());
        $line->recalculate(Money::fromString('2000.00'), self::at());
        $history = $line->releaseEvents();

        $restored = EarningLine::reconstitute($history);

        self::assertTrue($restored->id->equals($line->id));
        self::assertSame(self::state($line), self::state($restored));
        self::assertSame([], $restored->releaseEvents());

        $restored->recalculate(Money::fromString('3000.00'), self::at());
        self::assertSame(105000, $restored->currentValue()->cents);
        $restored->addManualAdjustment(Money::fromString('5.00'), new AdjustmentComment('New correction'), 'user-2', self::at());

        $newEvents = $restored->releaseEvents();
        self::assertCount(2, $newEvents);
        self::assertInstanceOf(SystemRecalculationIgnored::class, $newEvents[0]);
        self::assertInstanceOf(ManualAdjustmentAdded::class, $newEvents[1]);
        self::assertSame(3, $newEvents[1]->sequence);
        self::assertSame(105500, $restored->currentValue()->cents);
        self::assertSame(7, $restored->version);
        self::assertSame(105000, $line->currentValue()->cents);
    }

    #[Test]
    public function reconstitutesUnlockedLineThatCanStillBeRecalculated(): void
    {
        $line = EarningLine::reconstitute([
            new SystemValueCalculated(new EarningLineId('line-42'), Money::fromString('1000.00'), self::at()),
            new SystemValueCalculated(new EarningLineId('line-42'), Money::fromString('1050.00'), self::at('10:00:00')),
        ]);

        self::assertFalse($line->isLocked());
        self::assertSame([], $line->releaseEvents());
        $line->recalculate(Money::fromString('1075.00'), self::at('11:00:00'));

        self::assertSame(107500, $line->currentValue()->cents);
        self::assertSame(3, $line->version);
        $events = $line->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(SystemValueCalculated::class, $events[0]);
    }

    #[Test]
    public function replaysHistoricalCalculationsAndAppliesTheLockPolicyToNewCommands(): void
    {
        $id = new EarningLineId('line-42');
        $line = EarningLine::reconstitute([
            new SystemValueCalculated($id, Money::fromString('1000.00'), self::at()),
            new ManualAdjustmentAdded(
                $id,
                1,
                Money::fromString('-45.55'),
                new AdjustmentComment('Benefit correction'),
                'user-1',
                self::at('10:00:00'),
            ),
            new SystemValueCalculated($id, Money::fromString('1075.00'), self::at('11:00:00')),
        ]);

        self::assertSame(107500, $line->systemValue->cents);
        self::assertSame(102945, $line->currentValue()->cents);
        self::assertTrue($line->isLocked());
        self::assertSame(3, $line->version);
        self::assertSame([], $line->releaseEvents());
        $attemptedAmount = Money::fromString('1100.00');
        $at = self::at('12:00:00');

        $line->recalculate($attemptedAmount, $at);

        self::assertSame(107500, $line->systemValue->cents);
        self::assertSame(102945, $line->currentValue()->cents);
        self::assertEquals([new SystemRecalculationIgnored($id, $attemptedAmount, $at)], $line->releaseEvents());
    }

    #[Test]
    public function rejectsEmptyHistory(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EarningLine::reconstitute([]);
    }

    /** @param list<DomainEvent> $history */
    #[Test]
    #[DataProvider('historiesWithoutInitialCalculation')]
    public function rejectsHistoryWithoutInitialSystemCalculation(array $history): void
    {
        $this->expectException(InvalidArgumentException::class);

        EarningLine::reconstitute($history);
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
        ];
    }

    #[Test]
    public function rejectsHistoryContainingAnotherEarningLine(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EarningLine::reconstitute([
            new SystemValueCalculated(new EarningLineId('line-42'), Money::fromString('1000.00'), self::at()),
            new ManualAdjustmentAdded(
                new EarningLineId('line-43'),
                1,
                Money::fromString('1.00'),
                new AdjustmentComment('Correction'),
                'user-1',
                self::at(),
            ),
        ]);
    }

    #[Test]
    public function rejectsHistoryContainingAnUnsupportedEvent(): void
    {
        $id = new EarningLineId('line-42');
        $unknownEvent = new readonly class ($id, self::at()) implements DomainEvent {
            public function __construct(public EarningLineId $lineId, public DateTimeImmutable $occurredAt)
            {
            }
        };
        $this->expectException(InvalidArgumentException::class);

        EarningLine::reconstitute([
            new SystemValueCalculated($id, Money::fromString('1000.00'), self::at()),
            $unknownEvent,
        ]);
    }

    #[Test]
    public function reproducesEveryStepAndAdjustmentFromTheBrief(): void
    {
        $line = self::calculatedLine();
        self::assertSame('$1,000.00', $line->currentValue()->format());

        $line->recalculate(Money::fromString('1050.00'), self::at('10:00:00'));
        self::assertSame('$1,050.00', $line->currentValue()->format());

        $line->addManualAdjustment(
            Money::fromString('-45.55'),
            new AdjustmentComment('Employee declined dental benefit; reversing deduction'),
            'specialist-7',
            self::at('11:00:00'),
        );
        self::assertSame('$1,004.45', $line->currentValue()->format());

        $line->recalculate(Money::fromString('1075.00'), self::at('12:00:00'));
        self::assertSame('$1,004.45', $line->currentValue()->format());

        $remainingAdjustments = [
            ['+100.10', 'Late correction: missed approved overtime bonus', '$1,104.55'],
            ['-0.10', 'Minor rounding adjustment', '$1,104.45'],
            ['-0.20', 'Second minor rounding adjustment', '$1,104.25'],
            ['+0.20', 'Correcting mistake in adjustment #4', '$1,104.45'],
        ];

        foreach ($remainingAdjustments as [$amount, $comment, $expectedValue]) {
            $line->addManualAdjustment(Money::fromString($amount), new AdjustmentComment($comment), 'specialist-7', self::at('13:00:00'));
            self::assertSame($expectedValue, $line->currentValue()->format());
        }

        self::assertSame(105000, $line->systemValue->cents);
        self::assertSame(5445, $line->adjustmentsTotal->cents);
        self::assertSame(5, $line->adjustmentCount);
        self::assertSame(8, $line->version);
        $events = $line->releaseEvents();
        self::assertCount(8, $events);
        self::assertInstanceOf(SystemRecalculationIgnored::class, $events[3]);
        self::assertSame(107500, $events[3]->attemptedAmount->cents);
        $adjustments = array_values(array_filter($events, static fn (DomainEvent $event): bool => $event instanceof ManualAdjustmentAdded));
        self::assertSame([1, 2, 3, 4, 5], array_map(static fn (ManualAdjustmentAdded $event): int => $event->sequence, $adjustments));
        self::assertSame([-4555, 10010, -10, -20, 20], array_map(static fn (ManualAdjustmentAdded $event): int => $event->amount->cents, $adjustments));
        self::assertSame([
            'Employee declined dental benefit; reversing deduction',
            'Late correction: missed approved overtime bonus',
            'Minor rounding adjustment',
            'Second minor rounding adjustment',
            'Correcting mistake in adjustment #4',
        ], array_map(static fn (ManualAdjustmentAdded $event): string => $event->comment->value, $adjustments));
    }

    private static function calculatedLine(string $amount = '1000.00'): EarningLine
    {
        return EarningLine::calculate(new EarningLineId('line-42'), Money::fromString($amount), self::at());
    }

    private static function at(string $time = '09:00:00'): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-01T' . $time . '+00:00');
    }

    /** @return array{system: int, total: int, current: int, count: int, version: int, locked: bool} */
    private static function state(EarningLine $line): array
    {
        return [
            'system' => $line->systemValue->cents,
            'total' => $line->adjustmentsTotal->cents,
            'current' => $line->currentValue()->cents,
            'count' => $line->adjustmentCount,
            'version' => $line->version,
            'locked' => $line->isLocked(),
        ];
    }
}
