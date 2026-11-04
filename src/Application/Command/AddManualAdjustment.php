<?php

declare(strict_types=1);

namespace Payroll\Application\Command;

final readonly class AddManualAdjustment
{
    public function __construct(
        public string $lineId,
        public string $amount,
        public string $comment,
        public string $adjustedBy,
    ) {
    }
}
