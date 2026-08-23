<?php

declare(strict_types=1);

use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Envelope\HandledStamp;
use Infocyph\Omnibus\Handler\HandlerContext;
use Infocyph\Omnibus\Handler\HandlerInvoker;
use Infocyph\Omnibus\Handler\HandlerMap;
use Infocyph\Omnibus\Handler\HandlerMiddleware;
use Infocyph\Omnibus\Tests\Fixtures\RecordingHandlerMiddleware;
use Infocyph\Omnibus\Tests\Fixtures\TestCommand;
use Infocyph\Omnibus\Transport\SyncTransport;

test('handler invoker preserves synchronous and asynchronous callable arguments', function (): void {
    $envelope = new Envelope(new TestCommand('ready'));
    $calls = [];
    $invoker = new HandlerInvoker(new HandlerMap([
        TestCommand::class => static function (...$arguments) use (&$calls): string {
            $calls[] = $arguments;

            return 'handled';
        },
    ]));

    expect($invoker->invoke($envelope->message, $envelope, new HandlerContext('sync')))->toBe('handled')
        ->and($invoker->invoke($envelope->message, $envelope, new HandlerContext('work', 2, true)))
        ->toBe('handled')
        ->and($calls[0])->toHaveCount(3)
        ->and($calls[0][2])->toBe('sync')
        ->and($calls[1])->toHaveCount(2)
        ->and($calls[0][1])->toBe($envelope)
        ->and($calls[1][1])->toBe($envelope);
});

test('handler middleware nests in declaration order and preserves execution objects', function (): void {
    $events = [];
    $seen = [];
    $record = static function (
        string $event,
        object $message,
        Envelope $envelope,
        HandlerContext $context,
    ) use (&$events, &$seen): void {
        $events[] = $event;
        $seen[] = [$message, $envelope, $context];
    };
    $envelope = new Envelope(new TestCommand('ordered'));
    $context = new HandlerContext('work', 4, true);
    $invoker = new HandlerInvoker(
        new HandlerMap([
            TestCommand::class => static function () use (&$events): string {
                $events[] = 'handler';

                return 'result';
            },
        ]),
        [
            new RecordingHandlerMiddleware('a', $record),
            new RecordingHandlerMiddleware('b', $record),
        ],
    );

    expect($invoker->invoke($envelope->message, $envelope, $context))->toBe('result')
        ->and($events)->toBe(['before:a', 'before:b', 'handler', 'after:b', 'after:a']);
    foreach ($seen as [$message, $delivery, $metadata]) {
        expect($message)->toBe($envelope->message)
            ->and($delivery)->toBe($envelope)
            ->and($metadata)->toBe($context);
    }
});

test('handler middleware may transform or short circuit results', function (): void {
    $handled = 0;
    $transform = new class implements HandlerMiddleware {
        public function process(
            object $message,
            Envelope $envelope,
            HandlerContext $context,
            callable $next,
        ): mixed {
            return 'transformed-' . $next($message, $envelope, $context);
        }
    };
    $shortCircuit = new class implements HandlerMiddleware {
        public function process(
            object $message,
            Envelope $envelope,
            HandlerContext $context,
            callable $next,
        ): mixed {
            if ($message !== $envelope->message || $context->queue === '' || !is_callable($next)) {
                throw new LogicException('Handler middleware arguments are inconsistent.');
            }

            return 'cached';
        }
    };
    $handlers = new HandlerMap([
        TestCommand::class => static function () use (&$handled): string {
            $handled++;

            return 'handler';
        },
    ]);
    $envelope = new Envelope(new TestCommand('result'));

    expect((new HandlerInvoker($handlers, [$transform]))->invoke(
        $envelope->message,
        $envelope,
        new HandlerContext('sync'),
    ))->toBe('transformed-handler')
        ->and((new HandlerInvoker($handlers, [$shortCircuit]))->invoke(
            $envelope->message,
            $envelope,
            new HandlerContext('sync'),
        ))->toBe('cached')
        ->and($handled)->toBe(1);
});

test('handler and middleware exceptions propagate unchanged', function (): void {
    $handlerException = new RuntimeException('handler failure');
    $middlewareException = new DomainException('middleware failure');
    $envelope = new Envelope(new TestCommand('failure'));
    $context = new HandlerContext('work', 1, true);
    $throwingHandler = new HandlerInvoker(new HandlerMap([
        TestCommand::class => static fn() => throw $handlerException,
    ]));
    $throwingMiddleware = new class($middlewareException) implements HandlerMiddleware {
        public function __construct(private Throwable $exception) {}

        public function process(
            object $message,
            Envelope $envelope,
            HandlerContext $context,
            callable $next,
        ): mixed {
            if ($message !== $envelope->message || $context->queue === '' || !is_callable($next)) {
                throw new LogicException('Handler middleware arguments are inconsistent.');
            }

            throw $this->exception;
        }
    };

    try {
        $throwingHandler->invoke($envelope->message, $envelope, $context);
    } catch (Throwable $caught) {
        expect($caught)->toBe($handlerException);
    }

    try {
        (new HandlerInvoker(new HandlerMap([]), [$throwingMiddleware]))
            ->invoke($envelope->message, $envelope, $context);
    } catch (Throwable $caught) {
        expect($caught)->toBe($middlewareException);
    }
});

test('handler invoker materializes and validates middleware once', function (): void {
    $iterations = 0;
    $middleware = (static function () use (&$iterations): iterable {
        $iterations++;
        yield new RecordingHandlerMiddleware('once', static function (): void {});
    })();
    $envelope = new Envelope(new TestCommand('once'));
    $invoker = new HandlerInvoker(new HandlerMap([
        TestCommand::class => static fn(): string => 'ok',
    ]), $middleware);

    $invoker->invoke($envelope->message, $envelope, new HandlerContext('work', 1, true));
    $invoker->invoke($envelope->message, $envelope, new HandlerContext('work', 2, true));

    expect($iterations)->toBe(1)
        ->and(fn() => new HandlerInvoker(new HandlerMap([]), [new stdClass()]))
        ->toThrow(InvalidArgumentException::class);
});

test('sync transport applies middleware results and context without changing delay rejection', function (): void {
    $context = null;
    $handlerCalls = 0;
    $middleware = new class($context) implements HandlerMiddleware {
        public function __construct(public mixed &$seen) {}

        public function process(
            object $message,
            Envelope $envelope,
            HandlerContext $context,
            callable $next,
        ): mixed {
            $this->seen = $context;

            return 'wrapped-' . $next($message, $envelope, $context);
        }
    };
    $transport = new SyncTransport(new HandlerInvoker(new HandlerMap([
        TestCommand::class => static function (
            TestCommand $message,
            Envelope $envelope,
            string $queue,
        ) use (&$handlerCalls): string {
            $handlerCalls++;
            if ($envelope->message !== $message) {
                throw new LogicException('Sync handler envelope is inconsistent.');
            }

            return $message->value . '-' . $queue;
        },
    ]), [$middleware]));

    $result = $transport->send(new Envelope(new TestCommand('sync')), 'priority');

    expect($result->last(HandledStamp::class)?->result)->toBe('wrapped-sync-priority')
        ->and($handlerCalls)->toBe(1)
        ->and($context)->toBeInstanceOf(HandlerContext::class)
        ->and($context->queue)->toBe('priority')
        ->and($context->attempt)->toBe(1)
        ->and($context->asynchronous)->toBeFalse();
});

test('sync transport stamps short circuit results and propagates middleware exceptions', function (): void {
    $shortCircuit = new class implements HandlerMiddleware {
        public function process(
            object $message,
            Envelope $envelope,
            HandlerContext $context,
            callable $next,
        ): mixed {
            if ($message !== $envelope->message || $context->queue === '' || !is_callable($next)) {
                throw new LogicException('Handler middleware arguments are inconsistent.');
            }

            return 'short-circuited';
        }
    };
    $throwing = new class implements HandlerMiddleware {
        public function process(
            object $message,
            Envelope $envelope,
            HandlerContext $context,
            callable $next,
        ): mixed {
            if ($message !== $envelope->message || $context->queue === '' || !is_callable($next)) {
                throw new LogicException('Handler middleware arguments are inconsistent.');
            }

            throw new RuntimeException('sync middleware failure');
        }
    };
    $handlers = new HandlerMap([
        TestCommand::class => static fn(): string => 'handler',
    ]);
    $envelope = new Envelope(new TestCommand('sync'));
    $shortTransport = new SyncTransport(new HandlerInvoker($handlers, [$shortCircuit]));
    $throwingTransport = new SyncTransport(new HandlerInvoker($handlers, [$throwing]));

    expect($shortTransport->send($envelope, 'sync')->last(HandledStamp::class)?->result)
        ->toBe('short-circuited')
        ->and(fn() => $throwingTransport->send($envelope, 'sync'))
        ->toThrow(RuntimeException::class, 'sync middleware failure');
});
