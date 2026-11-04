<?php

declare(strict_types=1);

namespace Payroll\Infrastructure;

use Payroll\Application\ConcurrencyConflict;
use Payroll\Application\EventStore;
use Payroll\Domain\Event\DomainEvent;

final class InMemoryEventStore implements EventStore
{
    /** @var array<string, list<DomainEvent>> */
    private array $streams = [];

    /** @return list<DomainEvent> */
    public function load(string $streamId): array
    {
        return $this->streams[$streamId] ?? [];
    }

    /** @param list<DomainEvent> $events */
    public function append(string $streamId, array $events, int $expectedVersion): void
    {
        $currentEvents = $this->load($streamId);
        $actualVersion = count($currentEvents);

        if ($expectedVersion !== $actualVersion) {
            throw new ConcurrencyConflict(sprintf(
                'Expected version %d for stream "%s", got %d.',
                $expectedVersion,
                $streamId,
                $actualVersion,
            ));
        }

        $this->streams[$streamId] = [...$currentEvents, ...$events];
    }
}
