<?php

declare(strict_types=1);

namespace Payroll\Application;

use InvalidArgumentException;
use Payroll\Domain\EarningLine;
use Payroll\Domain\EarningLineId;

final class EarningLineRepository
{
    public function __construct(private readonly EventStore $eventStore)
    {
    }

    public function find(EarningLineId $id): ?EarningLine
    {
        $events = $this->eventStore->load($id->value);

        if ($events === []) {
            return null;
        }

        try {
            $line = EarningLine::reconstitute($events);
        } catch (InvalidArgumentException $exception) {
            throw new CorruptedEventStream(
                sprintf('Event stream "%s" contains an invalid earning line history.', $id->value),
                previous: $exception,
            );
        }

        if (!$line->id->equals($id)) {
            throw new CorruptedEventStream(sprintf('Event 1 in stream "%s" belongs to another earning line.', $id->value));
        }

        return $line;
    }

    public function get(EarningLineId $id): EarningLine
    {
        return $this->find($id) ?? throw new EarningLineNotFound(sprintf('Earning line "%s" was not found.', $id->value));
    }

    public function save(EarningLine $line): void
    {
        // Keep the original pending events intact until the store accepts them.
        $events = (clone $line)->releaseEvents();
        $this->eventStore->append($line->id->value, $events, $line->version - count($events));
        $line->releaseEvents();
    }
}
