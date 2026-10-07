<?php

declare(strict_types=1);

namespace Infocyph\Omnibus;

use Infocyph\Omnibus\Envelope\DelayStamp;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Envelope\HandledStamp;
use Infocyph\Omnibus\Envelope\MessageIdStamp;
use Infocyph\Omnibus\Envelope\RouteStamp;
use Infocyph\Omnibus\Integration\Runwire\RunwireBinding;
use Infocyph\Omnibus\Routing\RouteMap;
use Infocyph\Omnibus\Transport\TransportRegistry;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\UID\ULID;

final readonly class MessageBus
{
    private RunwireBinding $runwire;

    public function __construct(
        private RouteMap $routes,
        private TransportRegistry $transports,
        ?RunwireBinding $runwire = null,
    ) {
        $this->runwire = $runwire ?? new RunwireBinding();
    }

    public function dispatch(object $message): Envelope
    {
        return $this->runwire->run(fn(): Envelope => $this->dispatchBound($message));
    }

    public function runwireBinding(): RunwireBinding
    {
        return $this->runwire;
    }

    public function withRunwire(
        RuntimeContext $runtime,
        callable $callback,
        ?RequestContext $request = null,
        ?CoroutineScope $scope = null,
    ): mixed {
        return $this->runwire->withRunwire($runtime, $callback, $request, $scope);
    }

    private function dispatchBound(object $message): Envelope
    {
        $envelope = Envelope::wrap($message)->without(RouteStamp::class, HandledStamp::class);
        if ($envelope->last(MessageIdStamp::class) === null) {
            $envelope = $envelope->with(new MessageIdStamp(ULID::generateMonotonic()));
        }

        $route = $this->routes->for($envelope->message);
        $envelope = $envelope->with(new RouteStamp($route->transport, $route->queue));
        if ($route->delaySeconds > 0.0 && $envelope->last(DelayStamp::class) === null) {
            $envelope = $envelope->with(new DelayStamp($route->delaySeconds));
        }

        return $this->transports->get($route->transport)->send($envelope, $route->queue);
    }
}
