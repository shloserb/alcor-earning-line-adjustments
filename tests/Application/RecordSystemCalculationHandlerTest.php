<?php

declare(strict_types=1);

namespace Payroll\Tests\Application;

use DateTimeImmutable;
use Payroll\Application\Command\RecordSystemCalculation;
use Payroll\Application\Command\RecordSystemCalculationHandler;
use Payroll\Application\EarningLineRepository;
use Payroll\Domain\AdjustmentComment;
use Payroll\Domain\EarningLineId;
use Payroll\Domain\Event\SystemRecalculationIgnored;
use Payroll\Domain\Event\SystemValueCalculated;
use Payroll\Domain\Exception\InvalidMoneyAmount;
use Payroll\Domain\Money;
use Payroll\Infrastructure\InMemoryEventStore;
use Payroll\Tests\Support\FixedClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RecordSystemCalculationHandlerTest extends TestCase
{
    private InMemoryEventStore $store;

    private EarningLineRepository $repository;

    private FixedClock $clock;

    private RecordSystemCalculationHandler $handler;

    protected function setUp(): void
    {
        $this->store = new InMemoryEventStore();
        $this->repository = new EarningLineRepository($this->store);
        $this->clock = new FixedClock(new DateTimeImmutable('2026-01-01T09:00:00+00:00'));
        $this->handler = new RecordSystemCalculationHandler($this->repository, $this->clock);
    }

    #[Test]
    public function createsALineUsingTheInjectedClock(): void
    {
        ($this->handler)(new RecordSystemCalculation('line-42', '1000.00'));

        $events = $this->store->load('line-42');
        self::assertCount(1, $events);
        self::assertInstanceOf(SystemValueCalculated::class, $events[0]);
        self::assertSame('line-42', $events[0]->lineId->value);
        self::assertSame(100000, $events[0]->amount->cents);
        self::assertSame($this->clock->now(), $events[0]->occurredAt);
        self::assertSame(100000, $this->repository->get(new EarningLineId('line-42'))->currentValue()->cents);
    }

    #[Test]
    public function recalculatesAnExistingUnlockedLine(): void
    {
        ($this->handler)(new RecordSystemCalculation('line-42', '1000.00'));
        $firstTime = $this->clock->now();
        $this->clock->advance('+1 hour');

        ($this->handler)(new RecordSystemCalculation('line-42', '1050.00'));

        $events = $this->store->load('line-42');
        self::assertCount(2, $events);
        self::assertInstanceOf(SystemValueCalculated::class, $events[1]);
        self::assertSame(105000, $events[1]->amount->cents);
        self::assertSame($firstTime, $events[0]->occurredAt);
        self::assertSame($this->clock->now(), $events[1]->occurredAt);
        self::assertSame(105000, $this->repository->get(new EarningLineId('line-42'))->currentValue()->cents);
    }

    #[Test]
    public function recordsAnIgnoredRecalculationAfterAManualAdjustment(): void
    {
        ($this->handler)(new RecordSystemCalculation('line-42', '1050.00'));
        $line = $this->repository->get(new EarningLineId('line-42'));
        $line->addManualAdjustment(Money::fromString('-45.55'), new AdjustmentComment('Benefit'), 'user-1', $this->clock->now());
        $this->repository->save($line);
        $this->clock->advance('+1 hour');

        ($this->handler)(new RecordSystemCalculation('line-42', '1075.00'));

        $events = $this->store->load('line-42');
        self::assertCount(3, $events);
        self::assertInstanceOf(SystemRecalculationIgnored::class, $events[2]);
        self::assertSame(107500, $events[2]->attemptedAmount->cents);
        self::assertSame($this->clock->now(), $events[2]->occurredAt);
        $restored = $this->repository->get(new EarningLineId('line-42'));
        self::assertSame(105000, $restored->systemValue->cents);
        self::assertSame(100445, $restored->currentValue()->cents);
        self::assertSame(3, $restored->version);
    }

    #[Test]
    #[DataProvider('invalidAmounts')]
    public function invalidRecalculationDoesNotChangeTheStore(string $amount): void
    {
        ($this->handler)(new RecordSystemCalculation('line-42', '1000.00'));
        $history = $this->store->load('line-42');
        $this->expectException(InvalidMoneyAmount::class);

        try {
            ($this->handler)(new RecordSystemCalculation('line-42', $amount));
        } finally {
            self::assertSame($history, $this->store->load('line-42'));
        }
    }

    /** @return array<string, array{string}> */
    public static function invalidAmounts(): array
    {
        return [
            'non-numeric' => ['invalid'],
            'excess precision' => ['1.234'],
            'empty' => [''],
        ];
    }

    #[Test]
    public function invalidInitialCalculationDoesNotCreateAStream(): void
    {
        $this->expectException(InvalidMoneyAmount::class);

        try {
            ($this->handler)(new RecordSystemCalculation('line-42', 'invalid'));
        } finally {
            self::assertSame([], $this->store->load('line-42'));
        }
    }
}
