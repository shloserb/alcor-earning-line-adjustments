<?php

declare(strict_types=1);

use Payroll\Application\Command\AddManualAdjustment;
use Payroll\Application\Command\AddManualAdjustmentHandler;
use Payroll\Application\Command\RecordSystemCalculation;
use Payroll\Application\Command\RecordSystemCalculationHandler;
use Payroll\Application\EarningLineRepository;
use Payroll\Application\Query\EarningLineHistoryQuery;
use Payroll\Domain\Exception\InvalidAdjustment;
use Payroll\Infrastructure\InMemoryEventStore;
use Payroll\Infrastructure\SystemClock;

require dirname(__DIR__) . '/vendor/autoload.php';

$store = new InMemoryEventStore();
$clock = new SystemClock();
$repository = new EarningLineRepository($store);
$calculate = new RecordSystemCalculationHandler($repository, $clock);
$adjust = new AddManualAdjustmentHandler($repository, $clock);
$query = new EarningLineHistoryQuery($store);
$lineId = 'line-42';
$specialist = 'specialist-7';

$steps = [
    ['System calculates the line', new RecordSystemCalculation($lineId, '1000.00'), '-'],
    ['System recalculates after source changes', new RecordSystemCalculation($lineId, '1050.00'), '-'],
    ['Specialist adds a manual correction', new AddManualAdjustment(
        $lineId,
        '-45.55',
        'Employee declined dental benefit; reversing deduction',
        $specialist,
    ), '-'],
    ['System attempts recalculation', new RecordSystemCalculation($lineId, '1075.00'), 'Ignored: line already has a manual correction'],
    ['Specialist adds a second correction', new AddManualAdjustment(
        $lineId,
        '+100.10',
        'Late correction: missed approved overtime bonus',
        $specialist,
    ), '-'],
    ['Specialist adds a third correction', new AddManualAdjustment(
        $lineId,
        '-0.10',
        'Minor rounding adjustment',
        $specialist,
    ), '-'],
    ['Specialist adds a fourth correction', new AddManualAdjustment(
        $lineId,
        '-0.20',
        'Second minor rounding adjustment',
        $specialist,
    ), '-'],
    ['Specialist adds a compensating correction', new AddManualAdjustment(
        $lineId,
        '+0.20',
        'Correcting mistake in adjustment #4',
        $specialist,
    ), '-'],
];

$stepRow = "%-4s | %-42s | %10s | %-54s | %13s\n";
printf("Scenario\n\n");
printf($stepRow, 'Step', 'Event', 'Amount', 'Comment', 'Current value');

foreach ($steps as $index => [$description, $command, $systemComment]) {
    if ($command instanceof RecordSystemCalculation) {
        $calculate($command);
        $history = $query->forLine($lineId);
        $amount = '-';
        $comment = $systemComment;
    } else {
        $adjust($command);
        $history = $query->forLine($lineId);
        $adjustment = array_slice($history->adjustments, -1)[0] ?? null;
        $amount = $adjustment?->amount->formatSigned() ?? '-';
        $comment = $adjustment?->comment->value ?? '-';
    }

    printf(
        $stepRow,
        $index + 1,
        $description,
        $amount,
        $comment,
        $history->currentValue->format(),
    );
}

$history = $query->forLine($lineId);
$historyRow = "%-32s | %s\n";
printf("\nFinal audit history\n\n");
printf($historyRow, 'Entry', 'Value');
printf($historyRow, 'System value (frozen at step 3)', $history->systemValue->format());

foreach ($history->adjustments as $entry) {
    printf($historyRow, sprintf('Adjustment %d', $entry->sequence), $entry->amount->formatSigned());
}

printf($historyRow, 'Current (new) value', $history->currentValue->format());

foreach ($history->ignoredRecalculations as $ignored) {
    printf("\nIgnored recalculation: %s\n", $ignored->attemptedAmount->format());
}

try {
    $adjust(new AddManualAdjustment($lineId, '10.00', '', $specialist));
} catch (InvalidAdjustment $exception) {
    printf("\nRejected adjustment (empty comment): %s\n", $exception->getMessage());
}
