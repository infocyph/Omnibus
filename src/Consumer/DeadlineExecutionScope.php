<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Consumer;

use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Integration\Runwire\RunwireBinding;
use Infocyph\Omnibus\Internal\Time;
use Psr\Clock\ClockInterface;

final readonly class DeadlineExecutionScope implements ExecutionScope
{
    public function __construct(
        private ExecutionScope $inner,
        private ClockInterface $clock,
        private float $timeoutSeconds,
        private ?RunwireBinding $runwire = null,
    ) {
        if (!is_finite($timeoutSeconds) || $timeoutSeconds <= 0.0) {
            throw new \InvalidArgumentException('Execution timeout must be positive.');
        }
    }

    public function run(Envelope $envelope, callable $handler): mixed
    {
        $seconds = $this->timeoutSeconds;
        $hostRemaining = $this->runwire?->remainingSeconds();
        if ($hostRemaining !== null) {
            $seconds = min($seconds, $hostRemaining);
        }
        $deadline = Time::toDate(Time::add(
            Time::fromDate($this->clock->now()),
            max(0.0, $seconds),
        ));
        $token = new CancellationToken(
            $this->clock,
            $deadline,
            $this->runwire?->isCancellationRequested(...),
        );
        $result = $this->inner->run(
            $envelope->with(new CancellationStamp($token)),
            $handler,
        );
        if ($token->isCancellationRequested()) {
            throw new ExecutionTimedOutAfterExecution($deadline);
        }

        return $result;
    }
}
