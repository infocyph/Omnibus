<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Tests\Fixtures;

use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Handler\HandlerContext;
use Infocyph\Omnibus\Handler\HandlerMiddleware;

final readonly class RecordingHandlerMiddleware implements HandlerMiddleware
{
    private \Closure $record;

    public function __construct(private string $name, callable $record)
    {
        $this->record = $record(...);
    }

    public function process(
        object $message,
        Envelope $envelope,
        HandlerContext $context,
        callable $next,
    ): mixed {
        ($this->record)('before:' . $this->name, $message, $envelope, $context);

        try {
            return $next($message, $envelope, $context);
        } finally {
            ($this->record)('after:' . $this->name, $message, $envelope, $context);
        }
    }
}
