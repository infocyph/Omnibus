<?php

declare(strict_types=1);

use Infocyph\Omnibus\Consumer\Consumer;
use Infocyph\Omnibus\Consumer\Worker;
use Infocyph\Omnibus\Consumer\WorkerOptions;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Failure\InMemoryFailureStore;
use Infocyph\Omnibus\Handler\HandlerInvoker;
use Infocyph\Omnibus\Handler\HandlerMap;
use Infocyph\Omnibus\Retry\ExponentialRetryStrategy;
use Infocyph\Omnibus\Tests\Fixtures\FrozenClock;
use Infocyph\Omnibus\Tests\Fixtures\RecordingWorkerLifecycle;
use Infocyph\Omnibus\Tests\Fixtures\TestCommand;
use Infocyph\Omnibus\Transport\InMemoryTransport;

test('core and FPM-compatible paths keep process backends optional', function (): void {
    $composerPath = dirname(__DIR__, 2) . '/composer.json';
    $composer = json_decode((string) file_get_contents($composerPath), true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($composer['require'] ?? null) || !is_array($composer['require-dev'] ?? null)) {
        throw new RuntimeException('Composer dependency sections are invalid.');
    }

    expect($composer['require'])
        ->not->toHaveKey('infocyph/runwire')
        ->not->toHaveKey('ext-pcntl')
        ->not->toHaveKey('ext-posix');
    expect($composer['require-dev']['infocyph/runwire'] ?? null)->toBe('^1.0');

    $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    $transport = new InMemoryTransport($clock);
    $transport->send(new Envelope(new TestCommand('core')), 'work');
    $handled = 0;
    $lifecycle = new RecordingWorkerLifecycle(
        onStopRequested: static fn(int $checks): bool => $checks > 1,
    );
    $worker = new Worker(
        new Consumer(
            $transport,
            new HandlerInvoker(new HandlerMap([
                TestCommand::class => static function () use (&$handled): void {
                    $handled++;
                },
            ])),
            new ExponentialRetryStrategy(),
            new InMemoryFailureStore(),
            $clock,
        ),
        new WorkerOptions(
            queue: 'work',
            prefetch: 1,
            idleSleepSeconds: 0,
            maxIdleSleepSeconds: 0,
            handleSignals: false,
        ),
        $lifecycle,
    );

    $worker->run();

    expect($handled)->toBe(1)
        ->and($transport->size('work'))->toBe(0)
        ->and($lifecycle->heartbeats)->toBeGreaterThanOrEqual(1);
});
