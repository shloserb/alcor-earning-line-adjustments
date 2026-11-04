<?php

declare(strict_types=1);

namespace Payroll\Application\Command;

use Payroll\Application\Clock;
use Payroll\Application\EarningLineRepository;
use Payroll\Domain\EarningLine;
use Payroll\Domain\EarningLineId;
use Payroll\Domain\Money;

final class RecordSystemCalculationHandler
{
    public function __construct(
        private readonly EarningLineRepository $repository,
        private readonly Clock $clock,
    ) {
    }

    public function __invoke(RecordSystemCalculation $command): void
    {
        $id = new EarningLineId($command->lineId);
        $amount = Money::fromString($command->amount);
        $line = $this->repository->find($id);
        $occurredAt = $this->clock->now();

        if ($line === null) {
            $line = EarningLine::calculate($id, $amount, $occurredAt);
        } else {
            $line->recalculate($amount, $occurredAt);
        }

        $this->repository->save($line);
    }
}
