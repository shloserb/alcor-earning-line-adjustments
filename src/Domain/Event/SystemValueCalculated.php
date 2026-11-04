<?php

declare(strict_types=1);

namespace Payroll\Domain\Event;

use DateTimeImmutable;
use Payroll\Domain\EarningLineId;
use Payroll\Domain\Money;

final readonly class SystemValueCalculated implements DomainEvent
{
    public function __construct(
        public EarningLineId $lineId,
        public Money $amount,
        public DateTimeImmutable $occurredAt,
    ) {
    }
}
