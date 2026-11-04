<?php

declare(strict_types=1);

namespace Payroll\Application\Command;

use Payroll\Application\Clock;
use Payroll\Application\EarningLineRepository;
use Payroll\Domain\AdjustmentComment;
use Payroll\Domain\EarningLineId;
use Payroll\Domain\Money;

final class AddManualAdjustmentHandler
{
    public function __construct(
        private readonly EarningLineRepository $repository,
        private readonly Clock $clock,
    ) {
    }

    public function __invoke(AddManualAdjustment $command): void
    {
        $line = $this->repository->get(new EarningLineId($command->lineId));
        $line->addManualAdjustment(
            Money::fromString($command->amount),
            new AdjustmentComment($command->comment),
            $command->adjustedBy,
            $this->clock->now(),
        );
        $this->repository->save($line);
    }
}
