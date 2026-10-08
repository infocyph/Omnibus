<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration as CacheRunwireIntegration;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\Omnibus\Consumer\Consumer;
use Infocyph\Omnibus\Consumer\Worker;
use Infocyph\Omnibus\Consumer\WorkerOptions;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Failure\InMemoryFailureStore;
use Infocyph\Omnibus\Handler\HandlerInvoker;
use Infocyph\Omnibus\Handler\HandlerMap;
use Infocyph\Omnibus\Integration\Runwire\RunwireBinding;
use Infocyph\Omnibus\MessageBus;
use Infocyph\Omnibus\Retry\ExponentialRetryStrategy;
use Infocyph\Omnibus\Routing\Route;
use Infocyph\Omnibus\Routing\RouteMap;
use Infocyph\Omnibus\Tests\Fixtures\FrozenClock;
use Infocyph\Omnibus\Tests\Fixtures\TestCommand;
use Infocyph\Omnibus\Transport\InMemoryTransport;
use Infocyph\Omnibus\Transport\Sender;
use Infocyph\Omnibus\Transport\TransportRegistry;
use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeCapabilities;
use Infocyph\Runwire\RuntimeContext;

function omnibusRunwireRuntime(int $generation = 1, bool $coroutines = false): RuntimeContext
{
    return RuntimeContext::fromCapabilities(
        new RuntimeCapabilities(
            RuntimeDriver::NATIVE,
            persistentProcess: true,
            persistentApplication: true,
            runwireLoopAvailable: $coroutines,
            supportsRunwireCoroutines: $coroutines,
        ),
        'omnibus-test',
        workerSlot: 0,
        generation: $generation,
        concurrent: $coroutines,
    );
}

test('Runwire binding forwards exact host identities and restores every borrowed owner', function (): void {
    $runtime = omnibusRunwireRuntime();
    $request = RequestContext::create($runtime, requestId: 'omnibus-request-1');
    $binding = new RunwireBinding();
    $connection = new Connection(ConnectionConfig::fromArray([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]));
    $binding->registerConnection($connection);
    CacheRunwireIntegration::bind($runtime);

    $observed = [];
    $sender = new class($binding, $connection, $observed) implements Sender {
        /** @param array<string, mixed> $observed */
        public function __construct(
            private RunwireBinding $binding,
            private Connection $connection,
            private array &$observed,
        ) {}

        public function send(Envelope $envelope, string $queue): Envelope
        {
            unset($queue);
            $db = $this->connection->runwireBinding();
            $cache = CacheRunwireIntegration::current();
            $this->observed = [
                'runtime' => $this->binding->runtime(),
                'request' => $this->binding->request(),
                'db_runtime' => $db['runtime'] ?? null,
                'db_request' => $db['request'] ?? null,
                'cache_runtime' => $cache?->runtime,
                'cache_request' => $cache?->request,
            ];

            return $envelope;
        }
    };
    $bus = new MessageBus(
        new RouteMap(default: new Route('recording')),
        new TransportRegistry(['recording' => $sender]),
        $binding,
    );

    try {
        $bus->withRunwire(
            $runtime,
            fn(): Envelope => $bus->dispatch(new TestCommand('bound')),
            $request,
        );

        expect($observed['runtime'])->toBe($runtime)
            ->and($observed['request'])->toBe($request)
            ->and($observed['db_runtime'])->toBe($runtime)
            ->and($observed['db_request'])->toBe($request)
            ->and($observed['cache_runtime'])->toBe($runtime)
            ->and($observed['cache_request'])->toBe($request)
            ->and($binding->runtime())->toBeNull()
            ->and($binding->request())->toBeNull()
            ->and($connection->runwireBinding())->toBeNull()
            ->and(CacheRunwireIntegration::current())->toBeNull();
    } finally {
        CacheRunwireIntegration::release($runtime);
    }
});

test('late adapter registration in a bound callback still forwards nested dispatch ownership', function (): void {
    $runtime = omnibusRunwireRuntime();
    $request = RequestContext::create($runtime, requestId: 'late-registration');
    $binding = new RunwireBinding();
    $connection = new Connection(ConnectionConfig::fromArray([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]));
    $observed = null;
    $sender = new class($connection, $observed) implements Sender {
        public function __construct(private Connection $connection, private mixed &$observed) {}

        public function send(Envelope $envelope, string $queue): Envelope
        {
            unset($queue);
            $this->observed = $this->connection->runwireBinding();

            return $envelope;
        }
    };
    $bus = new MessageBus(
        new RouteMap(default: new Route('recording')),
        new TransportRegistry(['recording' => $sender]),
        $binding,
    );

    $bus->withRunwire($runtime, function () use ($binding, $connection, $bus): void {
        $binding->registerConnection($connection);
        $bus->dispatch(new TestCommand('late-registration'));
    }, $request);

    expect($observed['runtime'] ?? null)->toBe($runtime)
        ->and($observed['request'] ?? null)->toBe($request)
        ->and($connection->runwireBinding())->toBeNull()
        ->and($binding->runtime())->toBeNull();
});

test('a host can bind CacheLayer after entering Omnibus and forward the nested operation', function (): void {
    $runtime = omnibusRunwireRuntime();
    $request = RequestContext::create($runtime);
    $observed = null;
    $sender = new class($observed) implements Sender {
        public function __construct(private mixed &$observed) {}

        public function send(Envelope $envelope, string $queue): Envelope
        {
            unset($queue);
            $this->observed = CacheRunwireIntegration::current();

            return $envelope;
        }
    };
    $bus = new MessageBus(
        new RouteMap(default: new Route('recording')),
        new TransportRegistry(['recording' => $sender]),
    );

    try {
        $bus->withRunwire($runtime, static function () use ($bus, $runtime): void {
            CacheRunwireIntegration::bind($runtime);
            $bus->dispatch(new TestCommand('late-cache-binding'));
        }, $request);

        expect($observed?->runtime)->toBe($runtime)
            ->and($observed?->request)->toBe($request)
            ->and(CacheRunwireIntegration::current())->toBeNull()
            ->and(CacheRunwireIntegration::runtime())->toBe($runtime);
    } finally {
        CacheRunwireIntegration::release($runtime);
        $request->complete();
    }
});

test('cold binding does not autoload an unused optional CacheLayer integration', function (): void {
    $probe = <<<'PHP'
require $argv[1];
$runtime = Infocyph\Runwire\RuntimeContext::standalone();
$request = Infocyph\Runwire\RequestContext::create($runtime);
$binding = new Infocyph\Omnibus\Integration\Runwire\RunwireBinding();
$before = class_exists(Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration::class, false);
$result = $binding->withRunwire($runtime, fn() => $binding->run(fn() => 42), $request);
$after = class_exists(Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration::class, false);
$request->complete();
fwrite(STDOUT, json_encode([$before, $result, $after], JSON_THROW_ON_ERROR));
PHP;
    $process = proc_open(
        [PHP_BINARY, '-r', $probe, dirname(__DIR__, 2).'/vendor/autoload.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start the cold binding probe.');
    }
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    expect(proc_close($process))->toBe(0)
        ->and($errors)->toBe('')
        ->and($output)->toBe('[false,42,false]');
});

test('one Consumer can be reused across completed Runwire requests without retaining context', function (): void {
    $runtime = omnibusRunwireRuntime();
    $binding = new RunwireBinding();
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $transport = new InMemoryTransport($clock);
    $seen = [];
    $consumer = new Consumer(
        $transport,
        new HandlerInvoker(new HandlerMap([
            TestCommand::class => static function () use (&$seen, $binding): void {
                $seen[] = $binding->request()?->requestId;
            },
        ])),
        new ExponentialRetryStrategy(),
        new InMemoryFailureStore(),
        $clock,
        runwire: $binding,
    );

    foreach (['first', 'second'] as $index => $requestId) {
        $request = RequestContext::create($runtime, requestId: $requestId);
        $transport->send(new Envelope(new TestCommand($requestId)), 'work');
        $consumer->withRunwire(
            $runtime,
            fn() => $consumer->run('work'),
            $request,
        );
        $request->complete();

        expect($binding->runtime())->toBeNull()
            ->and($binding->request())->toBeNull();
    }

    expect($seen)->toBe(['first', 'second']);
});

test('Runwire binding rejects invalid ownership, cancellation, and stale runtime generations', function (): void {
    $binding = new RunwireBinding();
    $runtime = omnibusRunwireRuntime(generation: 2);
    $other = omnibusRunwireRuntime(generation: 2);
    $request = RequestContext::create($runtime, requestId: 'valid');

    $binding->withRunwire($runtime, static fn(): null => null, $request);

    $wrongRequest = RequestContext::create($other, requestId: 'wrong-runtime');
    expect(fn() => $binding->withRunwire($runtime, static fn(): null => null, $wrongRequest))
        ->toThrow(LogicException::class, 'different runtime');

    $completed = RequestContext::create($runtime, requestId: 'completed');
    $completed->complete();
    expect(fn() => $binding->withRunwire($runtime, static fn(): null => null, $completed))
        ->toThrow(LogicException::class, 'Completed');

    $cancelled = RequestContext::create($runtime, requestId: 'cancelled');
    $cancelled->cancel(CancellationReason::HOST_CANCELLED);
    expect(fn() => $binding->withRunwire($runtime, static fn(): null => null, $cancelled))
        ->toThrow(Infocyph\Runwire\Exception\CancelledException::class);

    expect(fn() => $binding->withRunwire(
        omnibusRunwireRuntime(generation: 1),
        static fn(): null => null,
    ))->toThrow(LogicException::class, 'Stale');

    $wrongPid = new RuntimeContext(
        driver: $runtime->driver,
        mode: $runtime->mode,
        workerSlot: $runtime->workerSlot,
        generation: 3,
        pid: $runtime->pid + 1,
        persistent: $runtime->persistent,
        concurrent: $runtime->concurrent,
        ownsListener: $runtime->ownsListener,
        ownsEventLoop: $runtime->ownsEventLoop,
        ownsWorkerPool: $runtime->ownsWorkerPool,
        capabilities: $runtime->capabilities,
    );
    expect(fn() => $binding->withRunwire($wrongPid, static fn(): null => null))
        ->toThrow(LogicException::class, 'PID');
});

test('an already bound Fiber cannot resume with an obsolete Runwire generation', function (): void {
    $binding = new RunwireBinding();
    $first = omnibusRunwireRuntime(generation: 1);
    $newer = omnibusRunwireRuntime(generation: 2);
    $performedBusinessWork = false;
    $fiber = new Fiber(function () use ($binding, $first, &$performedBusinessWork): void {
        $binding->withRunwire($first, function () use ($binding, &$performedBusinessWork): void {
            Fiber::suspend();
            $binding->run(static function () use (&$performedBusinessWork): void {
                $performedBusinessWork = true;
            });
        });
    });

    $fiber->start();
    expect($fiber->isSuspended())->toBeTrue();
    $binding->withRunwire($newer, static fn(): null => null);

    expect(fn() => $fiber->resume())->toThrow(LogicException::class, 'Stale')
        ->and($performedBusinessWork)->toBeFalse()
        ->and($fiber->isTerminated())->toBeTrue()
        ->and($binding->runtime())->toBeNull();
});

test('closed coroutine scopes reject Runwire binding before executing callbacks', function (): void {
    $binding = new RunwireBinding();
    $runtime = omnibusRunwireRuntime(coroutines: true);
    $scope = null;
    $ran = false;
    (new CoroutineRuntime())->run(static function (CoroutineScope $activeScope) use (&$scope): void {
        $scope = $activeScope;
    });

    expect($scope)->toBeInstanceOf(CoroutineScope::class)
        ->and(fn() => $binding->withRunwire(
            $runtime,
            static function () use (&$ran): void {
                $ran = true;
            },
            scope: $scope,
        ))->toThrow(LogicException::class, 'already closed')
        ->and($ran)->toBeFalse()
        ->and($binding->scope())->toBeNull();

    (new CoroutineRuntime())->run(static function (CoroutineScope $activeScope) use ($binding, $runtime): void {
        expect($binding->withRunwire(
            $runtime,
            static fn(): string => 'open scope',
            scope: $activeScope,
        ))->toBe('open scope');
    });
});

test('a host closing its borrowed scope blocks business work inside an active binding', function (): void {
    $binding = new RunwireBinding();
    $runtime = omnibusRunwireRuntime(coroutines: true);
    $ran = false;

    (new CoroutineRuntime())->run(static function (CoroutineScope $scope) use ($binding, $runtime, &$ran): void {
        $binding->withRunwire($runtime, static function () use ($binding, $scope, &$ran): void {
            $scope->close();

            expect(fn() => $binding->run(static function () use (&$ran): void {
                $ran = true;
            }))->toThrow(LogicException::class, 'already closed');
        }, scope: $scope);
    });

    expect($ran)->toBeFalse()
        ->and($binding->scope())->toBeNull();
});

if (function_exists('pcntl_fork') && function_exists('pcntl_waitpid') && function_exists('posix_kill')) {
    test('an inherited active Runwire binding rejects operations in a forked process', function (): void {
        $binding = new RunwireBinding();
        $runtime = omnibusRunwireRuntime();

        $binding->withRunwire($runtime, static function () use ($binding): void {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Unable to fork the binding ownership probe.');
            }
            if ($pid === 0) {
                $signal = SIGUSR2;
                try {
                    $binding->run(static fn(): null => null);
                } catch (LogicException $failure) {
                    $signal = str_contains($failure->getMessage(), 'PID') ? SIGUSR1 : SIGUSR2;
                }

                pcntl_signal($signal, SIG_DFL);
                pcntl_sigprocmask(SIG_UNBLOCK, [$signal]);
                $childPid = getmypid();
                if (!is_int($childPid) || !posix_kill($childPid, $signal)) {
                    throw new RuntimeException('Unable to terminate the binding ownership probe.');
                }
                while (true) {
                    usleep(10_000);
                }
            }

            $reaped = pcntl_waitpid($pid, $status);
            expect($reaped)->toBe($pid)
                ->and(pcntl_wifsignaled($status))->toBeTrue()
                ->and(pcntl_wtermsig($status))->toBe(SIGUSR1);
        });

        expect($binding->runtime())->toBeNull();
    });
}

test('Worker idle waiting borrows a Runwire coroutine scope and retains synchronous fallback', function (): void {
    $runtime = omnibusRunwireRuntime(coroutines: true);
    $request = RequestContext::create($runtime, requestId: 'coroutine-worker');
    $binding = new RunwireBinding();
    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $consumer = new Consumer(
        new InMemoryTransport($clock),
        new HandlerInvoker(new HandlerMap([])),
        new ExponentialRetryStrategy(),
        new InMemoryFailureStore(),
        $clock,
        runwire: $binding,
    );
    $worker = new Worker(
        $consumer,
        new WorkerOptions(
            idleSleepSeconds: 0.001,
            maxIdleSleepSeconds: 0.001,
            idleJitterRatio: 0,
            maxRuntimeSeconds: 0.003,
        ),
    );

    $coroutines = new CoroutineRuntime();
    $coroutines->run(function (CoroutineScope $scope) use ($worker, $runtime, $request): void {
        $worker->withRunwire(
            $runtime,
            static function () use ($worker): void {
                $worker->run();
            },
            $request,
            $scope,
        );
    });

    $fallback = new RunwireBinding();
    $started = hrtime(true);
    $fallback->sleep(0.001);
    expect((hrtime(true) - $started) / 1_000_000_000)->toBeGreaterThanOrEqual(0.0005);
});


test('Runwire binding restores nested ownership after exceptions', function (): void {
    $runtime = omnibusRunwireRuntime();
    $request = RequestContext::create($runtime, requestId: 'nested-request');
    $binding = new RunwireBinding();

    expect(fn() => $binding->withRunwire(
        $runtime,
        function () use ($binding, $runtime, $request): void {
            expect($binding->runtime())->toBe($runtime)
                ->and($binding->request())->toBe($request);

            $binding->withRunwire(
                $runtime,
                function () use ($binding, $runtime, $request): void {
                    expect($binding->runtime())->toBe($runtime)
                        ->and($binding->request())->toBe($request);
                },
            );

            throw new DomainException('nested-binding-failure');
        },
        $request,
    ))->toThrow(DomainException::class, 'nested-binding-failure');

    expect($binding->runtime())->toBeNull()
        ->and($binding->request())->toBeNull()
        ->and($binding->scope())->toBeNull();
});

test('Runwire binding isolates concurrent request ownership by Fiber', function (): void {
    $runtime = omnibusRunwireRuntime(coroutines: true);
    $leftRequest = RequestContext::create($runtime, requestId: 'left-request');
    $rightRequest = RequestContext::create($runtime, requestId: 'right-request');
    $binding = new RunwireBinding();
    $seen = [];
    $coroutines = new CoroutineRuntime();

    $coroutines->run(function (CoroutineScope $scope) use (
        $runtime,
        $leftRequest,
        $rightRequest,
        $binding,
        &$seen,
    ): void {
        $left = $scope->spawn(function () use ($scope, $runtime, $leftRequest, $binding, &$seen): void {
            $binding->withRunwire(
                $runtime,
                function () use ($scope, $binding, &$seen): void {
                    $seen[] = 'left-before:' . $binding->request()?->requestId;
                    $scope->yieldNow();
                    $seen[] = 'left-after:' . $binding->request()?->requestId;
                },
                $leftRequest,
                $scope,
            );
        });
        $right = $scope->spawn(function () use ($scope, $runtime, $rightRequest, $binding, &$seen): void {
            $binding->withRunwire(
                $runtime,
                function () use ($scope, $binding, &$seen): void {
                    $seen[] = 'right-before:' . $binding->request()?->requestId;
                    $scope->yieldNow();
                    $seen[] = 'right-after:' . $binding->request()?->requestId;
                },
                $rightRequest,
                $scope,
            );
        });

        $left->await();
        $right->await();
    });

    expect($seen)->toContain(
        'left-before:left-request',
        'left-after:left-request',
        'right-before:right-request',
        'right-after:right-request',
    )->and($binding->runtime())->toBeNull()
        ->and($binding->request())->toBeNull();
});
