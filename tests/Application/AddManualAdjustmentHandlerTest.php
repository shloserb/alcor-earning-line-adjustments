<?php

declare(strict_types=1);

namespace Payroll\Tests\Application;

use DateTimeImmutable;
use Payroll\Application\Command\AddManualAdjustment;
use Payroll\Application\Command\AddManualAdjustmentHandler;
use Payroll\Application\Command\RecordSystemCalculation;
use Payroll\Application\Command\RecordSystemCalculationHandler;
use Payroll\Application\EarningLineNotFound;
use Payroll\Application\EarningLineRepository;
use Payroll\Domain\EarningLine;
use Payroll\Domain\EarningLineId;
use Payroll\Domain\Event\ManualAdjustmentAdded;
use Payroll\Domain\Event\SystemValueCalculated;
use Payroll\Domain\Exception\InvalidAdjustment;
use Payroll\Domain\Exception\InvalidMoneyAmount;
use Payroll\Domain\Money;
use Payroll\Infrastructure\InMemoryEventStore;
use Payroll\Tests\Support\FixedClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

final class AddManualAdjustmentHandlerTest extends TestCase
{
    private InMemoryEventStore $store;

    private EarningLineRepository $repository;

    private FixedClock $clock;

    private AddManualAdjustmentHandler $handler;

    protected function setUp(): void
    {
        $this->store = new InMemoryEventStore();
        $this->repository = new EarningLineRepository($this->store);
        $this->clock = new FixedClock(new DateTimeImmutable('2026-01-01T09:00:00+00:00'));
        $this->handler = new AddManualAdjustmentHandler($this->repository, $this->clock);
    }

    #[Test]
    public function savesAdjustmentMetadataUsingTheInjectedClock(): void
    {
        $this->calculateLine();
        $this->clock->advance('+1 hour');
        $firstAdjustmentTime = $this->clock->now();

        ($this->handler)(new AddManualAdjustment('line-42', '-45.55', '  Benefit correction  ', ' specialist-7 '));
        $this->clock->advance('+15 minutes');
        ($this->handler)(new AddManualAdjustment('line-42', '+100.10', 'Late bonus', 'specialist-8'));

        $events = $this->store->load('line-42');
        self::assertCount(3, $events);
        self::assertInstanceOf(ManualAdjustmentAdded::class, $events[1]);
        self::assertSame('line-42', $events[1]->lineId->value);
        self::assertSame(-4555, $events[1]->amount->cents);
        self::assertSame('Benefit correction', $events[1]->comment->value);
        self::assertSame(' specialist-7 ', $events[1]->adjustedBy);
        self::assertSame(1, $events[1]->sequence);
        self::assertSame($firstAdjustmentTime, $events[1]->occurredAt);
        self::assertInstanceOf(ManualAdjustmentAdded::class, $events[2]);
        self::assertSame(10010, $events[2]->amount->cents);
        self::assertSame(2, $events[2]->sequence);
        self::assertSame($this->clock->now(), $events[2]->occurredAt);
        $line = $this->repository->get(new EarningLineId('line-42'));
        self::assertSame(105455, $line->currentValue()->cents);
        self::assertTrue($line->isLocked());
    }

    #[Test]
    public function cannotAdjustALineBeforeItsInitialSystemCalculation(): void
    {
        $this->expectException(EarningLineNotFound::class);

        try {
            ($this->handler)(new AddManualAdjustment('unknown-line', '10.00', 'Bonus', 'user-1'));
        } finally {
            self::assertSame([], $this->store->load('unknown-line'));
        }
    }

    #[Test]
    #[DataProvider('invalidCommentText')]
    public function rejectedCommentLeavesTheLineUnlockedAndAllowsAutomaticRecalculation(string $comment): void
    {
        $this->calculateLine();
        $id = new EarningLineId('line-42');
        $history = $this->store->load('line-42');

        try {
            ($this->handler)(new AddManualAdjustment('line-42', '-45.55', $comment, 'user-1'));
            self::fail('An invalid comment must be rejected before locking the line.');
        } catch (InvalidAdjustment) {
            self::assertSame($history, $this->store->load('line-42'));
            $line = $this->repository->get($id);
            self::assertSame(1, $line->version);
            self::assertSame(0, $line->adjustmentCount);
            self::assertSame(100000, $line->currentValue()->cents);
            self::assertFalse($line->isLocked());
        }

        $recalculate = new RecordSystemCalculationHandler($this->repository, $this->clock);
        $recalculate(new RecordSystemCalculation('line-42', '1050.00'));

        $events = $this->store->load('line-42');
        self::assertCount(2, $events);
        self::assertInstanceOf(SystemValueCalculated::class, $events[1]);
        self::assertSame(105000, $events[1]->amount->cents);
        $line = $this->repository->get($id);
        self::assertSame(2, $line->version);
        self::assertSame(0, $line->adjustmentCount);
        self::assertSame(105000, $line->currentValue()->cents);
        self::assertFalse($line->isLocked());
    }

    /** @return array<string, array{string}> */
    public static function invalidCommentText(): array
    {
        return [
            'non-breaking space' => ["\u{00A0}"],
            'Unicode whitespace' => ["\u{0085}\u{2000}\u{2028}\u{2029}\u{202F}\u{205F}\u{3000}"],
            'zero-width format characters' => ["\u{200B}\u{FEFF}\u{2060}"],
            'invalid UTF-8' => ["Correction\xC3\x28"],
        ];
    }

    #[Test]
    public function preservesUnicodeCommentTextInTheAuditEvent(): void
    {
        $this->calculateLine();
        $comment = "Корекція\u{00A0}для\u{2009}給与 / اصلاح\u{200C}حقوق";

        ($this->handler)(new AddManualAdjustment('line-42', '10.00', '  ' . $comment . '  ', 'user-1'));

        $events = $this->store->load('line-42');
        self::assertCount(2, $events);
        self::assertInstanceOf(ManualAdjustmentAdded::class, $events[1]);
        self::assertSame($comment, $events[1]->comment->value);
    }

    /** @param class-string<Throwable> $expectedException */
    #[Test]
    #[DataProvider('invalidAdjustments')]
    public function rejectedAdjustmentDoesNotChangeStoredHistory(
        string $amount,
        string $comment,
        string $adjustedBy,
        string $expectedException,
    ): void {
        $this->calculateLine();
        ($this->handler)(new AddManualAdjustment('line-42', '10.00', 'Valid bonus', 'user-1'));
        $history = $this->store->load('line-42');
        $this->expectException($expectedException);

        try {
            ($this->handler)(new AddManualAdjustment('line-42', $amount, $comment, $adjustedBy));
        } finally {
            self::assertSame($history, $this->store->load('line-42'));
            self::assertSame(101000, $this->repository->get(new EarningLineId('line-42'))->currentValue()->cents);
        }
    }

    /** @return array<string, array{string, string, string, class-string<Throwable>}> */
    public static function invalidAdjustments(): array
    {
        return [
            'non-numeric amount' => ['invalid', 'Correction', 'user-1', InvalidMoneyAmount::class],
            'excess precision' => ['1.234', 'Correction', 'user-1', InvalidMoneyAmount::class],
            'zero amount' => ['0.00', 'Correction', 'user-1', InvalidAdjustment::class],
            'empty comment' => ['1.00', '', 'user-1', InvalidAdjustment::class],
            'blank comment' => ['1.00', '   ', 'user-1', InvalidAdjustment::class],
            'empty actor' => ['1.00', 'Correction', '', InvalidAdjustment::class],
            'blank actor' => ['1.00', 'Correction', "\t \n", InvalidAdjustment::class],
        ];
    }

    #[Test]
    public function overflowingAdjustmentDoesNotChangeStoredHistory(): void
    {
        $this->repository->save(EarningLine::calculate(new EarningLineId('line-42'), Money::fromCents(PHP_INT_MAX), $this->clock->now()));
        $history = $this->store->load('line-42');
        $this->expectException(InvalidMoneyAmount::class);

        try {
            ($this->handler)(new AddManualAdjustment('line-42', '0.01', 'Correction', 'user-1'));
        } finally {
            self::assertSame($history, $this->store->load('line-42'));
        }
    }

    #[Test]
    public function adjustingOneLineDoesNotChangeAnotherLine(): void
    {
        $this->calculateLine();
        $this->repository->save(EarningLine::calculate(new EarningLineId('line-43'), Money::fromString('2000.00'), $this->clock->now()));
        $otherHistory = $this->store->load('line-43');

        ($this->handler)(new AddManualAdjustment('line-42', '-10.00', 'Correction', 'user-1'));

        self::assertSame($otherHistory, $this->store->load('line-43'));
        self::assertSame(99000, $this->repository->get(new EarningLineId('line-42'))->currentValue()->cents);
    }

    private function calculateLine(): void
    {
        $this->repository->save(EarningLine::calculate(new EarningLineId('line-42'), Money::fromString('1000.00'), $this->clock->now()));
    }
}
