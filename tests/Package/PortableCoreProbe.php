<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Tests\Package;

use Infocyph\Omnibus\Clock\SystemClock;
use Infocyph\Omnibus\Consumer\Consumer;
use Infocyph\Omnibus\Consumer\NativeWorkerPoolBackend;
use Infocyph\Omnibus\Consumer\Worker;
use Infocyph\Omnibus\Consumer\WorkerOptions;
use Infocyph\Omnibus\Consumer\WorkerPool;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Failure\InMemoryFailureStore;
use Infocyph\Omnibus\Handler\HandlerInvoker;
use Infocyph\Omnibus\Handler\HandlerMap;
use Infocyph\Omnibus\Retry\ExponentialRetryStrategy;
use Infocyph\Omnibus\Transport\InMemoryTransport;
use RuntimeException;
use stdClass;

$autoload = $argv[1] ?? '';
if ($autoload === '' || !is_file($autoload)) {
    throw new RuntimeException('Portable-core probe requires the isolated consumer autoloader path.');
}

require $autoload;

if (extension_loaded('pcntl') || extension_loaded('posix')) {
    throw new RuntimeException('Portable-core probe must run with PCNTL and POSIX unavailable.');
}

$clock = new SystemClock();
$transport = new InMemoryTransport($clock);
$message = new stdClass();
$message->value = 'portable';
$transport->send(new Envelope($message), 'portable');
$handled = 0;
$consumer = new Consumer(
    $transport,
    new HandlerInvoker(new HandlerMap([
        stdClass::class => static function (stdClass $message) use (&$handled): void {
            if ($message->value !== 'portable') {
                throw new RuntimeException('Portable worker received the wrong message.');
            }
            $handled++;
        },
    ])),
    new ExponentialRetryStrategy(initialDelaySeconds: 0),
    new InMemoryFailureStore(),
    $clock,
);
$worker = new Worker(
    $consumer,
    new WorkerOptions(
        queue: 'portable',
        maxMessages: 1,
        idleSleepSeconds: 0,
        maxIdleSleepSeconds: 0,
    ),
);
$worker->run();

if ($handled !== 1 || $transport->size('portable') !== 0) {
    throw new RuntimeException('Portable Worker did not complete the normal message lifecycle.');
}

try {
    (new Worker(
        $consumer,
        new WorkerOptions(handleSignals: true),
    ))->run();
    throw new RuntimeException('Explicit Worker signal handling unexpectedly started without PCNTL.');
} catch (RuntimeException $failure) {
    if (!str_contains($failure->getMessage(), 'requires ext-pcntl')) {
        throw $failure;
    }
}

try {
    (new WorkerPool(
        static fn(): Worker => $worker,
        backend: new NativeWorkerPoolBackend(),
    ))->run();
    throw new RuntimeException('Native WorkerPool unexpectedly started without process extensions.');
} catch (RuntimeException $failure) {
    if (!str_contains($failure->getMessage(), 'requires ext-pcntl and ext-posix')) {
        throw $failure;
    }
}

fwrite(STDOUT, "portable-core-ok\n");
