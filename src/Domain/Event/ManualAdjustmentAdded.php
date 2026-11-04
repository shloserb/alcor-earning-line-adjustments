<?php

declare(strict_types=1);

namespace Payroll\Domain\Event;

use DateTimeImmutable;
use Payroll\Domain\AdjustmentComment;
use Payroll\Domain\EarningLineId;
use Payroll\Domain\Money;

final readonly class ManualAdjustmentAdded implements DomainEvent
{
    public function __construct(
        public EarningLineId $lineId,
        public int $sequence,
        public Money $amount,
        public AdjustmentComment $comment,
        public string $adjustedBy,
        public DateTimeImmutable $occurredAt,
    ) {
    }
}
