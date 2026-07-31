<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Consumer;

use Infocyph\Omnibus\Envelope\MessageIdStamp;
use Infocyph\Omnibus\Failure\FailedMessage;
use Infocyph\Omnibus\Failure\FailureStore;
use Infocyph\Omnibus\Handler\HandlerMap;
use Infocyph\Omnibus\Retry\RetryStrategy;
use Infocyph\Omnibus\Transport\Receiver;
use Psr\Clock\ClockInterface;

final readonly class Consumer
{
    public function __construct(
        private Receiver $receiver,
        private HandlerMap $handlers,
        private RetryStrategy $retry,
        private FailureStore $failures,
        private ClockInterface $clock,
        private ExecutionScope $scope = new DirectExecutionScope(),
    ) {}

    public function run(
        string $queue = 'default',
        int $limit = 1,
        float $visibilitySeconds = 60.0,
    ): ConsumerResult {
        $received = $succeeded = $released = $failed = 0;
        foreach ($this->receiver->receive($queue, $limit, $visibilitySeconds) as $reservation) {
            $received++;
            $decodeFailure = $reservation->decodingFailure();
            if ($decodeFailure !== null) {
                $this->failures->add(FailedMessage::undecodable(
                    self::failureId($reservation->receipt, $reservation->queue),
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

            try {
                $handler = $this->handlers->for($envelope->message);
                $this->scope->run($envelope, $handler);
            } catch (\Throwable $exception) {
                if ($this->retry->shouldRetry($exception, $reservation->attempt)) {
                    $this->receiver->release(
                        $reservation,
                        $this->retry->delaySeconds($reservation->attempt),
                    );
                    $released++;

                    continue;
                }

                $messageIdStamp = $envelope->last(MessageIdStamp::class);
                $messageId = $messageIdStamp instanceof MessageIdStamp
                    ? $messageIdStamp->id
                    : $reservation->receipt;
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
                $failed++;

                continue;
            }

            $this->receiver->acknowledge($reservation);
            $succeeded++;
        }

        return new ConsumerResult($received, $succeeded, $released, $failed);
    }

    private static function failureId(string $receipt, string $queue): string
    {
        return strlen($receipt) <= 191
            ? $receipt
            : 'receipt-' . hash('sha256', $queue . "\0" . $receipt);
    }
}
