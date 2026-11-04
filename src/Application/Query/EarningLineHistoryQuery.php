<?php

declare(strict_types=1);

namespace Payroll\Application\Query;

use Payroll\Application\CorruptedEventStream;
use Payroll\Application\EarningLineNotFound;
use Payroll\Application\EventStore;
use Payroll\Domain\Event\ManualAdjustmentAdded;
use Payroll\Domain\Event\SystemRecalculationIgnored;
use Payroll\Domain\Event\SystemValueCalculated;
use Payroll\Domain\Money;

final class EarningLineHistoryQuery
{
    public function __construct(private readonly EventStore $eventStore)
    {
    }

    public function forLine(string $lineId): EarningLineHistory
    {
        $events = $this->eventStore->load($lineId);

        if ($events === []) {
            throw new EarningLineNotFound(sprintf('Earning line "%s" was not found.', $lineId));
        }

        if (!($events[0] instanceof SystemValueCalculated)) {
            throw new CorruptedEventStream(sprintf('Event 1 in stream "%s" must be a system calculation.', $lineId));
        }

        $systemValue = Money::fromCents(0);
        $adjustmentsTotal = Money::fromCents(0);
        $lockedAt = null;
        $adjustments = [];
        $ignoredRecalculations = [];

        foreach ($events as $index => $event) {
            if ($event->lineId->value !== $lineId) {
                throw new CorruptedEventStream(sprintf('Event %d in stream "%s" belongs to another earning line.', $index + 1, $lineId));
            }

            // Project recorded facts without reapplying current command policies.
            if ($event instanceof SystemValueCalculated) {
                $systemValue = $event->amount;
            } elseif ($event instanceof ManualAdjustmentAdded) {
                $lockedAt ??= $event->occurredAt;
                $adjustmentsTotal = $adjustmentsTotal->add($event->amount);
                $adjustments[] = new AdjustmentEntry(
                    $event->sequence,
                    $event->amount,
                    $event->comment,
                    $event->adjustedBy,
                    $event->occurredAt,
                );
            } elseif ($event instanceof SystemRecalculationIgnored) {
                $ignoredRecalculations[] = new IgnoredRecalculation($event->attemptedAmount, $event->occurredAt);
            } else {
                throw new CorruptedEventStream(sprintf('Event %d in stream "%s" has an unsupported type.', $index + 1, $lineId));
            }
        }

        return new EarningLineHistory(
            $events[0]->lineId,
            $systemValue,
            $lockedAt,
            $adjustments,
            $ignoredRecalculations,
            $systemValue->add($adjustmentsTotal),
        );
    }
}
