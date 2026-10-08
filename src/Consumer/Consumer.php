<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Consumer;

use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Envelope\MessageIdStamp;
use Infocyph\Omnibus\Failure\FailedMessage;
use Infocyph\Omnibus\Failure\FailureInput;
use Infocyph\Omnibus\Failure\FailureStore;
use Infocyph\Omnibus\Handler\HandlerContext;
use Infocyph\Omnibus\Handler\HandlerInvoker;
use Infocyph\Omnibus\Integration\Runwire\RunwireBinding;
use Infocyph\Omnibus\Retry\RetryStrategy;
use Infocyph\Omnibus\Transport\Receiver;
use Infocyph\Omnibus\Transport\Reservation;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\RuntimeContext;
use Psr\Clock\ClockInterface;

final readonly class Consumer
{
    private RunwireBinding $runwire;

    public function __construct(
        private Receiver $receiver,
        private HandlerInvoker $invoker,
        private RetryStrategy $retry,
        private FailureStore $failures,
        private ClockInterface $clock,
        private ExecutionScope $scope = new DirectExecutionScope(),
        ?RunwireBinding $runwire = null,
    ) {
        $this->runwire = $runwire ?? new RunwireBinding();
    }

    public function run(
        string $queue = 'default',
        int $limit = 1,
        float $visibilitySeconds = 60.0,
    ): ConsumerResult {
        return $this->runwire->run(
            fn(): ConsumerResult => $this->consume($queue, $limit, $visibilitySeconds),
        );
    }

    public function runwireBinding(): RunwireBinding
    {
        return $this->runwire;
    }

    public function withRunwire(
        RuntimeContext $runtime,
        callable $callback,
        ?RequestContext $request = null,
        ?CoroutineScope $scope = null,
    ): mixed {
        return $this->runwire->withRunwire($runtime, $callback, $request, $scope);
    }

    private function consume(string $queue, int $limit, float $visibilitySeconds): ConsumerResult
    {
        $received = $succeeded = $released = $failed = 0;
        $this->runwire->checkpoint();
        foreach ($this->receiver->receive($queue, $limit, $visibilitySeconds) as $reservation) {
            $this->runwire->checkpoint();
            $received++;
            $decodeFailure = $reservation->decodingFailure();
            if ($decodeFailure !== null) {
                $this->failures->add(FailedMessage::undecodable(
                    FailureInput::id($reservation->messageId, $reservation->queue, $reservation->receipt),
                    $reservation->queue,
                    $decodeFailure->payload,
                    $reservation->attempt,
                    $this->clock->now(),
                    $decodeFailure->failureClass,
                    $decodeFailure->reason,
                    $decodeFailure->truncated,
                ));
                $this->receiver->reject($reservation);
                $failed++;

                continue;
            }
            $envelope = $reservation->envelope();
            $context = new HandlerContext(
                queue: $reservation->queue,
                attempt: $reservation->attempt,
                asynchronous: true,
            );

            try {
                $this->scope->run(
                    $envelope,
                    function (object $message, \Infocyph\Omnibus\Envelope\Envelope $delivery) use ($context): mixed {
                        $this->runwire->checkpoint();

                        return $this->invoker->invoke($message, $delivery, $context);
                    },
                );
            } catch (\Throwable $exception) {
                if ($this->settleFailure($exception, $reservation, $envelope)) {
                    $released++;
                } else {
                    $failed++;
                }

                continue;
            }

            $this->runwire->cleanup(function () use ($reservation): void {
                $this->receiver->acknowledge($reservation);
            });
            $succeeded++;
            $this->runwire->checkpoint();
        }

        return new ConsumerResult($received, $succeeded, $released, $failed);
    }

    private function settleFailure(
        \Throwable $exception,
        Reservation $reservation,
        Envelope $envelope,
    ): bool {
        if ($this->runwire->isHostCancellation($exception)) {
            throw $exception;
        }

        $this->runwire->checkpoint();
        if ($this->retry->shouldRetry($exception, $reservation->attempt)) {
            $this->receiver->release(
                $reservation,
                $this->retry->delaySeconds($reservation->attempt),
            );

            return true;
        }

        $messageIdStamp = $envelope->last(MessageIdStamp::class);
        $messageId = $messageIdStamp instanceof MessageIdStamp
            ? $messageIdStamp->id
            : FailureInput::id('', $reservation->queue, $reservation->receipt);
        $this->failures->add(FailedMessage::decoded(
            $messageId,
            $reservation->queue,
            $envelope,
            $reservation->attempt,
            $this->clock->now(),
            $exception::class,
            $exception->getMessage(),
        ));
        $this->receiver->reject($reservation);

        return false;
    }
}
