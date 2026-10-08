<?php

declare(strict_types=1);

use Infocyph\Omnibus\Envelope\HandledStamp;
use Infocyph\Omnibus\Handler\HandlerInvoker;
use Infocyph\Omnibus\Handler\HandlerMap;
use Infocyph\Omnibus\MessageBus;
use Infocyph\Omnibus\Routing\RouteMap;
use Infocyph\Omnibus\Transport\SyncTransport;
use Infocyph\Omnibus\Transport\TransportRegistry;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeCapabilities;
use Infocyph\Runwire\RuntimeContext;

require $argv[1] ?? dirname(__DIR__) . '/vendor/autoload.php';

final readonly class ExampleForwardedMessage
{
    public function __construct(public int $value) {}
}

$bus = new MessageBus(
    new RouteMap(),
    new TransportRegistry(['sync' => new SyncTransport(new HandlerInvoker(new HandlerMap([
        ExampleForwardedMessage::class => static fn(ExampleForwardedMessage $message): int => $message->value,
    ])))]),
);
$runtime = RuntimeContext::fromCapabilities(
    new RuntimeCapabilities(RuntimeDriver::NATIVE, persistentProcess: true, persistentApplication: true),
    'omnibus-consumer-smoke',
    workerSlot: 0,
    generation: 1,
);
$request = RequestContext::create($runtime);
$binding = $bus->runwireBinding();
$forward = static fn(int $value): mixed => $bus->withRunwire(
    $runtime,
    static fn(): mixed => $bus->dispatch(new ExampleForwardedMessage($value))->last(HandledStamp::class)?->result,
    $request,
);

try {
    if ($forward(41) !== 41) {
        throw new RuntimeException('Direct host forwarding failed.');
    }

    $intermediary = static fn(callable $next): mixed => $next();
    if ($intermediary(static fn(): mixed => $forward(42)) !== 42) {
        throw new RuntimeException('Intermediary host forwarding failed.');
    }

    $fiber = new Fiber(static fn(): mixed => $forward(43));
    $fiber->start();
    if ($fiber->getReturn() !== 43) {
        throw new RuntimeException('Fiber forwarding failed.');
    }

    $bus->withRunwire($runtime, static function () use ($binding, $request): void {
        if ($binding->request() !== $request) {
            throw new RuntimeException('Host request context was not forwarded.');
        }
        $binding->sleep(0.0); // Explicit fallback path, no coroutine scope is supplied.
    }, $request);
} finally {
    $request->complete();
}

if ($binding->request() !== null || $binding->runtime() !== null) {
    throw new RuntimeException('Host context leaked after forwarding.');
}

for ($index = 0; $index < 100; $index++) {
    $next = RequestContext::create($runtime);

    try {
        $result = $bus->withRunwire(
            $runtime,
            static fn(): mixed => $bus->dispatch(new ExampleForwardedMessage($index))
                ->last(HandledStamp::class)?->result,
            $next,
        );
        if ($result !== $index) {
            throw new RuntimeException('Persistent-host request returned the wrong result.');
        }
    } finally {
        $next->complete();
    }
}
if ($binding->request() !== null || $binding->runtime() !== null) {
    throw new RuntimeException('Persistent-host binding was not cleared.');
}

fwrite(STDOUT, "runwire-forwarding-ok\n");
