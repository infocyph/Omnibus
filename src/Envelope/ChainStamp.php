<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Envelope;

final readonly class ChainStamp implements Stamp
{
    public function __construct(
        public string $workflowId,
        public int $index,
    ) {
        if ($workflowId === '' || strlen($workflowId) > 26 || $index < 0) {
            throw new \InvalidArgumentException('Chain stamp requires a workflow ID and non-negative index.');
        }
    }
}
