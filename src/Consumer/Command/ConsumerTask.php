<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Consumer\Command;

use Infocyph\Omnibus\Consumer\Consumer;
use Infocyph\Omnibus\Consumer\ConsumerResult;

final readonly class ConsumerTask
{
    public function __construct(private Consumer $consumer) {}

    public function run(ConsumeRequest $request): ConsumerResult
    {
        return $this->consumer->run(
            $request->queue,
            $request->limit,
            $request->visibilitySeconds,
        );
    }
}
