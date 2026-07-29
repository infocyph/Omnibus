<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Telemetry;

use Infocyph\Omnibus\Consumer\ExecutionScope;
use Infocyph\Omnibus\Envelope\Envelope;

final readonly class ObservedExecutionScope implements ExecutionScope
{
    public function __construct(
        private ExecutionScope $inner,
        private TelemetrySink $telemetry,
    ) {}

    public function run(Envelope $envelope, callable $handler): mixed
    {
        $started = hrtime(true);

        try {
            $result = $this->inner->run($envelope, $handler);
        } catch (\Throwable $failure) {
            $this->telemetry->record('queue.processing.failed', 1, [
                'message' => $envelope->message::class,
                'failure' => $failure::class,
            ]);

            throw $failure;
        } finally {
            $this->telemetry->record(
                'queue.processing.duration_ms',
                (hrtime(true) - $started) / 1_000_000,
                ['message' => $envelope->message::class],
            );
        }
        $this->telemetry->record('queue.processing.succeeded', 1, [
            'message' => $envelope->message::class,
        ]);

        return $result;
    }
}
