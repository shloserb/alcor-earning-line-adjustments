<?php

declare(strict_types=1);

namespace Payroll\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Payroll\Domain\Event\DomainEvent;
use Payroll\Domain\Event\ManualAdjustmentAdded;
use Payroll\Domain\Event\SystemRecalculationIgnored;
use Payroll\Domain\Event\SystemValueCalculated;
use Payroll\Domain\Exception\InvalidAdjustment;

final class EarningLine
{
    public private(set) Money $systemValue;

    public private(set) Money $adjustmentsTotal;

    public private(set) int $adjustmentCount = 0;

    public private(set) int $version = 0;

    /** @var list<DomainEvent> */
    private array $recordedEvents = [];

    private function __construct(public readonly EarningLineId $id)
    {
        $this->systemValue = Money::fromCents(0);
        $this->adjustmentsTotal = Money::fromCents(0);
    }

    public static function calculate(EarningLineId $id, Money $amount, DateTimeImmutable $occurredAt): self
    {
        $line = new self($id);
        $line->record(new SystemValueCalculated($id, $amount, $occurredAt));

        return $line;
    }

    public function recalculate(Money $amount, DateTimeImmutable $occurredAt): void
    {
        if ($this->isLocked()) {
            $this->record(new SystemRecalculationIgnored($this->id, $amount, $occurredAt));

            return;
        }

        $this->record(new SystemValueCalculated($this->id, $amount, $occurredAt));
    }

    public function addManualAdjustment(
        Money $amount,
        AdjustmentComment $comment,
        string $adjustedBy,
        DateTimeImmutable $occurredAt,
    ): void {
        if ($amount->isZero()) {
            throw new InvalidAdjustment('A manual adjustment must not be zero.');
        }

        if (trim($adjustedBy) === '') {
            throw new InvalidAdjustment('A manual adjustment must identify who made it.');
        }

        // Validate both sums before recording an event or changing state.
        $newTotal = $this->adjustmentsTotal->add($amount);
        $this->systemValue->add($newTotal);

        $this->record(new ManualAdjustmentAdded(
            $this->id,
            $this->adjustmentCount + 1,
            $amount,
            $comment,
            $adjustedBy,
            $occurredAt,
        ));
    }

    /** @param list<DomainEvent> $events */
    public static function reconstitute(array $events): self
    {
        if ($events === [] || !($events[0] instanceof SystemValueCalculated)) {
            throw new InvalidArgumentException('An earning line history must begin with a system calculation.');
        }

        $line = new self($events[0]->lineId);

        foreach ($events as $event) {
            if (!$event->lineId->equals($line->id)) {
                throw new InvalidArgumentException('All events must belong to the same earning line.');
            }

            $line->apply($event);
        }

        return $line;
    }

    /** @return list<DomainEvent> */
    public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }

    public function currentValue(): Money
    {
        return $this->systemValue->add($this->adjustmentsTotal);
    }

    public function isLocked(): bool
    {
        return $this->adjustmentCount > 0;
    }

    private function record(DomainEvent $event): void
    {
        $this->apply($event);
        $this->recordedEvents[] = $event;
    }

    private function apply(DomainEvent $event): void
    {
        // Apply recorded facts; current business policies are enforced by command methods.
        if ($event instanceof SystemValueCalculated) {
            $this->systemValue = $event->amount;
        } elseif ($event instanceof ManualAdjustmentAdded) {
            $this->adjustmentsTotal = $this->adjustmentsTotal->add($event->amount);
            ++$this->adjustmentCount;
        } elseif (!($event instanceof SystemRecalculationIgnored)) {
            throw new InvalidArgumentException('Unsupported earning line event.');
        }

        ++$this->version;
    }
}
