<?php

declare(strict_types=1);

namespace Payroll\Application;

use RuntimeException;

final class ConcurrencyConflict extends RuntimeException
{
}
