<?php

declare(strict_types=1);

namespace Payroll\Application\Query;

use DateTimeImmutable;
use Payroll\Domain\EarningLineId;
use Payroll\Domain\Money;

final readonly class EarningLineHistory
{
    /**
     * @param list<AdjustmentEntry> $adjustments
     * @param list<IgnoredRecalculation> $ignoredRecalculations
     */
    public function __construct(
        public EarningLineId $lineId,
        public Money $systemValue,
        public ?DateTimeImmutable $lockedAt,
        public array $adjustments,
        public array $ignoredRecalculations,
        public Money $currentValue,
    ) {
    }
}
