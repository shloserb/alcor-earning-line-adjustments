<?php

declare(strict_types=1);

namespace Payroll\Domain\Event;

use DateTimeImmutable;
use Payroll\Domain\EarningLineId;

interface DomainEvent
{
    public EarningLineId $lineId { get; }

    public DateTimeImmutable $occurredAt { get; }
}
