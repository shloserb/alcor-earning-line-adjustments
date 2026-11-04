<?php

declare(strict_types=1);

namespace Payroll\Tests\Acceptance;

use DateTimeImmutable;
use Payroll\Application\Command\AddManualAdjustment;
use Payroll\Application\Command\AddManualAdjustmentHandler;
use Payroll\Application\Command\RecordSystemCalculation;
use Payroll\Application\Command\RecordSystemCalculationHandler;
use Payroll\Application\EarningLineRepository;
use Payroll\Application\Query\AdjustmentEntry;
use Payroll\Application\Query\EarningLineHistoryQuery;
use Payroll\Domain\Money;
use Payroll\Infrastructure\InMemoryEventStore;
use Payroll\Tests\Support\FixedClock;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BriefScenarioTest extends TestCase
{
    #[Test]
    public function finalHistoryMatchesBrief(): void
    {
        $store = new InMemoryEventStore();
        $clock = new FixedClock(new DateTimeImmutable('2026-01-01T09:00:00+00:00'));
        $repository = new EarningLineRepository($store);
        $calculate = new RecordSystemCalculationHandler($repository, $clock);
        $adjust = new AddManualAdjustmentHandler($repository, $clock);
        $query = new EarningLineHistoryQuery($store);

        $calculate(new RecordSystemCalculation('line-42', '1000.00'));
        self::assertCurrentValue($query, '$1,000.00');

        $clock->advance('+1 hour');
        $calculate(new RecordSystemCalculation('line-42', '1050.00'));
        self::assertCurrentValue($query, '$1,050.00');

        $clock->advance('+1 hour');
        $lockedAt = $clock->now();
        $adjustmentTimes = [$lockedAt];
        $adjust(new AddManualAdjustment(
            'line-42',
            '-45.55',
            'Employee declined dental benefit; reversing deduction',
            'specialist-7',
        ));
        self::assertCurrentValue($query, '$1,004.45');

        $clock->advance('+1 hour');
        $ignoredAt = $clock->now();
        $calculate(new RecordSystemCalculation('line-42', '1075.00'));
        self::assertCurrentValue($query, '$1,004.45');

        $remainingAdjustments = [
            ['+100.10', 'Late correction: missed approved overtime bonus', '$1,104.55'],
            ['-0.10', 'Minor rounding adjustment', '$1,104.45'],
            ['-0.20', 'Second minor rounding adjustment', '$1,104.25'],
            ['+0.20', 'Correcting mistake in adjustment #4', '$1,104.45'],
        ];

        foreach ($remainingAdjustments as [$amount, $comment, $expectedValue]) {
            $clock->advance('+1 hour');
            $adjustmentTimes[] = $clock->now();
            $adjust(new AddManualAdjustment('line-42', $amount, $comment, 'specialist-7'));
            self::assertCurrentValue($query, $expectedValue);
        }

        $history = $query->forLine('line-42');

        self::assertSame('line-42', $history->lineId->value);
        self::assertSame('$1,050.00', $history->systemValue->format());
        self::assertSame('$1,104.45', $history->currentValue->format());
        self::assertEquals($lockedAt, $history->lockedAt);
        self::assertCount(5, $history->adjustments);
        self::assertSame([1, 2, 3, 4, 5], array_map(static fn (AdjustmentEntry $entry): int => $entry->sequence, $history->adjustments));
        self::assertSame([-4555, 10010, -10, -20, 20], array_map(static fn (AdjustmentEntry $entry): int => $entry->amount->cents, $history->adjustments));
        self::assertSame([
            'Employee declined dental benefit; reversing deduction',
            'Late correction: missed approved overtime bonus',
            'Minor rounding adjustment',
            'Second minor rounding adjustment',
            'Correcting mistake in adjustment #4',
        ], array_map(static fn (AdjustmentEntry $entry): string => $entry->comment->value, $history->adjustments));
        self::assertSame(array_fill(0, 5, 'specialist-7'), array_map(static fn (AdjustmentEntry $entry): string => $entry->adjustedBy, $history->adjustments));
        self::assertEquals($adjustmentTimes, array_map(static fn (AdjustmentEntry $entry): DateTimeImmutable => $entry->at, $history->adjustments));
        self::assertCount(1, $history->ignoredRecalculations);
        self::assertSame('$1,075.00', $history->ignoredRecalculations[0]->attemptedAmount->format());
        self::assertEquals($ignoredAt, $history->ignoredRecalculations[0]->at);

        $adjustmentsTotal = Money::fromCents(0);

        foreach ($history->adjustments as $entry) {
            $adjustmentsTotal = $adjustmentsTotal->add($entry->amount);
        }

        self::assertTrue($history->systemValue->add($adjustmentsTotal)->equals($history->currentValue));
    }

    private static function assertCurrentValue(EarningLineHistoryQuery $query, string $expected): void
    {
        self::assertSame($expected, $query->forLine('line-42')->currentValue->format());
    }
}
