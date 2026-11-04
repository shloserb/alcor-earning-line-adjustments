<?php

declare(strict_types=1);

namespace Payroll\Application\Query;

use DateTimeImmutable;
use Payroll\Domain\AdjustmentComment;
use Payroll\Domain\Money;

final readonly class AdjustmentEntry
{
    public function __construct(
        public int $sequence,
        public Money $amount,
        public AdjustmentComment $comment,
        public string $adjustedBy,
        public DateTimeImmutable $at,
    ) {
    }
}
