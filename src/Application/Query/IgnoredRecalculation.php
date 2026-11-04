<?php

declare(strict_types=1);

namespace Payroll\Application\Query;

use DateTimeImmutable;
use Payroll\Domain\Money;

final readonly class IgnoredRecalculation
{
    public function __construct(
        public Money $attemptedAmount,
        public DateTimeImmutable $at,
    ) {
    }
}
