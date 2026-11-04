<?php

declare(strict_types=1);

namespace Payroll\Domain;

use Payroll\Domain\Exception\InvalidMoneyAmount;

final readonly class Money
{
    private function __construct(public int $cents)
    {
    }

    public static function fromCents(int $cents): self
    {
        return new self($cents);
    }

    public static function fromString(string $amount): self
    {
        if (preg_match('/^[+-]?\d+(?:\.\d{1,2})?$/D', $amount) !== 1) {
            throw new InvalidMoneyAmount('Money must be a decimal string with at most two fractional digits.');
        }

        $parts = explode('.', ltrim($amount, '+-'), 2);
        $digits = ltrim($parts[0] . str_pad($parts[1] ?? '', 2, '0'), '0');
        $digits = $digits === '' ? '0' : $digits;
        $signedDigits = str_starts_with($amount, '-') ? '-' . $digits : $digits;
        $cents = filter_var($signedDigits, FILTER_VALIDATE_INT);

        if ($cents === false) {
            throw new InvalidMoneyAmount('Money amount must fit in an integer number of cents.');
        }

        return new self($cents);
    }

    public function add(self $other): self
    {
        if (
            ($other->cents > 0 && $this->cents > PHP_INT_MAX - $other->cents)
            || ($other->cents < 0 && $this->cents < PHP_INT_MIN - $other->cents)
        ) {
            throw new InvalidMoneyAmount('Adding these amounts would exceed the supported integer range.');
        }

        return new self($this->cents + $other->cents);
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents;
    }

    public function format(): string
    {
        $digits = str_pad(ltrim((string) $this->cents, '-'), 3, '0', STR_PAD_LEFT);
        $whole = substr($digits, 0, -2);
        $groupedWhole = strrev(implode(',', str_split(strrev($whole), 3)));

        return ($this->cents < 0 ? '-' : '') . '$' . $groupedWhole . '.' . substr($digits, -2);
    }

    public function formatSigned(): string
    {
        return ($this->cents >= 0 ? '+' : '') . $this->format();
    }
}
