<?php

declare(strict_types=1);

use Infocyph\Omnibus\Clock\SystemClock;
use Infocyph\Omnibus\Consumer\Consumer;
use Infocyph\Omnibus\Consumer\Worker;
use Infocyph\Omnibus\Consumer\WorkerLifecycle;
use Infocyph\Omnibus\Consumer\WorkerOptions;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Event\EventDispatcher;
use Infocyph\Omnibus\Event\ListenerMap;
use Infocyph\Omnibus\Failure\FailedMessage;
use Infocyph\Omnibus\Failure\FailureManager;
use Infocyph\Omnibus\Failure\InMemoryFailureStore;
use Infocyph\Omnibus\Handler\HandlerInvoker;
use Infocyph\Omnibus\Handler\HandlerMap;
use Infocyph\Omnibus\MessageBus;
use Infocyph\Omnibus\Retry\ExponentialRetryStrategy;
use Infocyph\Omnibus\Routing\Route;
use Infocyph\Omnibus\Routing\RouteMap;
use Infocyph\Omnibus\Transport\InMemoryTransport;
use Infocyph\Omnibus\Transport\TransportRegistry;

require $argv[1] ?? dirname(__DIR__) . '/vendor/autoload.php';

final readonly class ExampleWork
{
    public function __construct(public string $key) {}
}

final readonly class ExampleWorkCompleted
{
    public function __construct(public string $key) {}
}

$clock = new SystemClock();
$transport = new InMemoryTransport($clock);
$effects = [];
$events = [];
$listenerProvider = new ListenerMap([
    ExampleWorkCompleted::class => [static function (ExampleWorkCompleted $event) use (&$events): void {
        $events[] = $event->key;
    }],
]);
$eventDispatcher = new EventDispatcher($listenerProvider);
$invoker = new HandlerInvoker(new HandlerMap([
    ExampleWork::class => static function (ExampleWork $message) use (&$effects, $eventDispatcher): void {
        if (isset($effects[$message->key])) {
            return;
        }

        $effects[$message->key] = true;
        $eventDispatcher->dispatch(new ExampleWorkCompleted($message->key));
    },
]));
$bus = new MessageBus(
    new RouteMap([ExampleWork::class => new Route('memory', 'work')]),
    new TransportRegistry(['memory' => $transport]),
);
$failures = new InMemoryFailureStore($clock);
$consumer = new Consumer(
    $transport,
    $invoker,
    new ExponentialRetryStrategy(initialDelaySeconds: 0.0),
    $failures,
    $clock,
);
$lifecycle = new class implements WorkerLifecycle {
    public int $heartbeats = 0;

    public function heartbeat(): void
    {
        $this->heartbeats++;
    }

    public function stopRequested(): bool
    {
        return false;
    }
};

$bus->dispatch(new ExampleWork('same-business-key'));
$bus->dispatch(new ExampleWork('same-business-key'));
$worker = new Worker($consumer, new WorkerOptions(
    queue: 'work',
    prefetch: 2,
    maxMessages: 2,
    idleSleepSeconds: 0.0,
    maxIdleSleepSeconds: 0.0,
));
$worker->runManaged($lifecycle);

if (count($effects) !== 1 || $events !== ['same-business-key']
    || $transport->size('work') !== 0 || $failures->all() !== []
    || $lifecycle->heartbeats < 2) {
    throw new RuntimeException('Standalone consumer, lifecycle, event or idempotency check failed.');
}

$failures->add(FailedMessage::decoded(
    'failed-example',
    'work',
    new Envelope(new ExampleWork('replayed-business-key')),
    1,
    $clock->now(),
    RuntimeException::class,
    'simulated-failure',
));
$manager = new FailureManager($failures);
$manager->retry('failed-example', $transport, 'work');
$replayed = $consumer->run('work');
if ($replayed->succeeded !== 1 || count($effects) !== 2
    || $events !== ['same-business-key', 'replayed-business-key']
    || $failures->find('failed-example') !== null) {
    throw new RuntimeException('Failure claim/replay and idempotent recovery failed.');
}

fwrite(STDOUT, "standalone-consumer-ok\n");
