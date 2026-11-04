<?php

declare(strict_types=1);

namespace Payroll\Infrastructure;

use DateTimeImmutable;
use Payroll\Application\Clock;

final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
