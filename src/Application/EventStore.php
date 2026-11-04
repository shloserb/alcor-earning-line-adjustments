<?php

declare(strict_types=1);

namespace Payroll\Application;

use Payroll\Domain\Event\DomainEvent;

/**
 * Append-only event streams with optimistic concurrency.
 *
 * This storage contract does not enforce earning-line policies or event identity.
 * Callers own the mapping between a stream ID and the events stored in that stream.
 */
interface EventStore
{
    /**
     * Return the complete stream in append order, or [] when it does not exist.
     *
     * @return list<DomainEvent>
     */
    public function load(string $streamId): array;

    /**
     * Atomically compare the current version and append the entire ordered batch.
     * Previously stored events and their order must be preserved.
     *
     * The version is the count of all stored events, including ignored attempts.
     * A new stream requires expectedVersion = 0. Even an empty batch must check
     * the expected version; a matching empty batch leaves history unchanged.
     * A version mismatch raises ConcurrencyConflict without changing the stream.
     *
     * Other failures in a persistent adapter may leave the commit outcome unknown;
     * callers must reconcile that outcome before retrying blindly.
     *
     * @param list<DomainEvent> $events
     * @throws ConcurrencyConflict
     */
    public function append(string $streamId, array $events, int $expectedVersion): void;
}
