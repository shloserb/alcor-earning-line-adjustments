<?php

declare(strict_types=1);

namespace Payroll\Domain;

use Payroll\Domain\Exception\InvalidAdjustment;

final readonly class AdjustmentComment
{
    public string $value;

    public function __construct(string $value)
    {
        $value = trim($value);

        // Require content beyond Unicode separators, controls and format characters; reject invalid UTF-8 too.
        if (preg_match('/[^\p{Z}\p{Cc}\p{Cf}]/u', $value) !== 1) {
            throw new InvalidAdjustment('An adjustment comment must contain non-blank UTF-8 text.');
        }

        $this->value = $value;
    }
}
