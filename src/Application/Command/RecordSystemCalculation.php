<?php

declare(strict_types=1);

namespace Payroll\Application\Command;

final readonly class RecordSystemCalculation
{
    public function __construct(public string $lineId, public string $amount)
    {
    }
}
