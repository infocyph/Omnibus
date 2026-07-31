<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Workflow;

use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Transport\QueueName;

final readonly class WorkflowItem
{
    public function __construct(
        public string $workflowId,
        public string $itemId,
        public int $index,
        public string $queue,
        public Envelope $envelope,
    ) {
        if (
            $workflowId === ''
            || strlen($workflowId) > 26
            || $itemId === ''
            || strlen($itemId) > 26
            || $index < 0
        ) {
            throw new \InvalidArgumentException('Workflow item fields are invalid.');
        }
        QueueName::assert($queue);
    }
}
