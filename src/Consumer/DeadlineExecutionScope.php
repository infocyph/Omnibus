<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Consumer;

use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Internal\Time;
use Psr\Clock\ClockInterface;

final readonly class DeadlineExecutionScope implements ExecutionScope
{
    public function __construct(
        private ExecutionScope $inner,
        private ClockInterface $clock,
        private float $timeoutSeconds,
    ) {
        if (!is_finite($timeoutSeconds) || $timeoutSeconds <= 0.0) {
            throw new \InvalidArgumentException('Execution timeout must be positive.');
        }
    }

    public function run(Envelope $envelope, callable $handler): mixed
    {
        $deadline = Time::toDate(Time::add(
            Time::fromDate($this->clock->now()),
            $this->timeoutSeconds,
        ));
        $token = new CancellationToken($this->clock, $deadline);
        $result = $this->inner->run(
            $envelope->with(new CancellationStamp($token)),
            $handler,
        );
        $token->throwIfCancellationRequested();

        return $result;
    }
}
