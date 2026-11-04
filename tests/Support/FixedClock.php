<?php

declare(strict_types=1);

namespace Payroll\Tests\Support;

use DateTimeImmutable;
use Payroll\Application\Clock;

final class FixedClock implements Clock
{
    public function __construct(private DateTimeImmutable $time)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }

    public function advance(string $modifier): void
    {
        $this->time = $this->time->modify($modifier);
    }
}
