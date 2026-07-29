<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Workflow;

use Infocyph\Omnibus\Envelope\Envelope;

final readonly class WorkflowItem
{
    public function __construct(
        public string $workflowId,
        public string $itemId,
        public int $index,
        public string $queue,
        public Envelope $envelope,
    ) {
        if ($workflowId === '' || $itemId === '' || $index < 0 || $queue === '') {
            throw new \InvalidArgumentException('Workflow item fields are invalid.');
        }
    }
}
