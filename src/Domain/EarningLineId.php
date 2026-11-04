<?php

declare(strict_types=1);

namespace Payroll\Domain;

use InvalidArgumentException;

final readonly class EarningLineId
{
    public function __construct(public string $value)
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException('An earning line ID must not be empty.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
