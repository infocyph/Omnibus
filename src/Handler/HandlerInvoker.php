<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Handler;

use Infocyph\Omnibus\Envelope\Envelope;

final readonly class HandlerInvoker
{
    /** @var (\Closure(object, Envelope, HandlerContext): mixed)|null */
    private ?\Closure $pipeline;

    /** @param iterable<HandlerMiddleware> $middleware */
    public function __construct(private HandlerMap $handlers, iterable $middleware = [])
    {
        $normalized = [];
        foreach ($middleware as $candidate) {
            $normalized[] = self::normalize($candidate);
        }

        if ($normalized === []) {
            $this->pipeline = null;

            return;
        }

        $handlers = $this->handlers;
        $next = static function (
            object $message,
            Envelope $envelope,
            HandlerContext $context,
        ) use ($handlers): mixed {
            $handler = $handlers->for($message);
            if ($context->asynchronous) {
                return $handler($message, $envelope);
            }

            return $handler($message, $envelope, $context->queue);
        };
        for ($index = count($normalized) - 1; $index >= 0; $index--) {
            $current = $normalized[$index];
            $next = static fn(
                object $message,
                Envelope $envelope,
                HandlerContext $context,
            ): mixed => $current->process($message, $envelope, $context, $next);
        }

        $this->pipeline = $next;
    }

    public function invoke(
        object $message,
        Envelope $envelope,
        HandlerContext $context,
    ): mixed {
        if ($this->pipeline === null) {
            $handler = $this->handlers->for($message);
            if ($context->asynchronous) {
                return $handler($message, $envelope);
            }

            return $handler($message, $envelope, $context->queue);
        }

        return ($this->pipeline)($message, $envelope, $context);
    }

    private static function normalize(mixed $middleware): HandlerMiddleware
    {
        if (!$middleware instanceof HandlerMiddleware) {
            throw new \InvalidArgumentException(sprintf(
                'Handler middleware must implement %s.',
                HandlerMiddleware::class,
            ));
        }

        return $middleware;
    }
}
